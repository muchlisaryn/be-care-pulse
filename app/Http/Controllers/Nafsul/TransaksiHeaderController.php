<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Header transaksi iuran Nafsul — satu baris = satu kuitansi pembayaran.
 *
 * Rincian iurannya ada di `TransaksiController` (`/nafsul/transaksi`); satu
 * header bisa menaungi banyak rincian.
 *
 * Nama kolom database dan nama field API sama-sama Inggris, dan responsnya JSON
 * polos — sama dengan `TransaksiController`.
 */
class TransaksiHeaderController extends Controller
{
    use HandlesTransactionRows;

    public function index(Request $request)
    {
        $query = TransactionHeader::query()->withCount('transactions');

        if ($search = $request->query('search')) {
            $query->where('transaction_number', 'like', "%{$search}%");
        }

        if ($metode = $request->query('payment_method')) {
            $query->where('payment_method', $metode);
        }

        if ($jenis = $request->query('transaction_type')) {
            $query->where('transaction_type', $jenis);
        }

        // Filter tanggal transaksi (bukan periode iuran — itu milik rincian).
        //
        // Dibaca dari kolom `date`, dengan `created_at` sebagai cadangan lewat
        // COALESCE. Cadangan itu untuk baris yang tanggalnya belum sempat terisi;
        // migrasi `add_date_to_transaction_headers` sudah mengisi baris lama, tapi
        // penyaring tidak boleh diam-diam membuang baris yang kebetulan kosong.
        if ($dari = $request->query('date_from')) {
            $query->whereRaw('DATE(COALESCE(`date`, created_at)) >= ?', [$dari]);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereRaw('DATE(COALESCE(`date`, created_at)) <= ?', [$sampai]);
        }

        // Nomor transaksi urut kronologis, jadi pengurutannya cukup dari situ.
        // `id` sebagai pemecah seri agar urutannya tidak berubah antar halaman.
        $query->orderByDesc('transaction_number')->orderByDesc('id');

        $data = $query->paginate($request->integer('per_page', 25));

        $data->getCollection()->transform(fn ($row) => $this->transform($row));

        return response()->json($data);
    }

    /**
     * Simpan satu kuitansi beserta seluruh rinciannya sekaligus.
     *
     * Rincian boleh memuat lebih dari satu anggota — satu kuitansi bisa
     * menampung setoran beberapa anggota dari kelompok yang sama.
     *
     * Seluruhnya dibungkus satu transaksi database: kalau ada satu baris
     * rincian yang ditolak, header-nya ikut dibatalkan. Tanpa itu, kegagalan di
     * tengah menyisakan kuitansi kosong yang nomornya sudah terpakai.
     */
    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $baris = $this->validateRows($request);

        return DB::transaction(function () use ($data, $baris) {
            // Nomor dibuat di sini bila tidak dikirim klien — sama seperti nomor
            // anggota, yang boleh diisi manual untuk mencatat kuitansi lama.
            if (empty($data['transaction_number'])) {
                $data['transaction_number'] = TransactionHeader::generateNumber();
            }

            // Total selalu mengikuti jumlah rinciannya, bukan angka kiriman
            // klien. Kalau keduanya boleh berbeda, header dan rincian bisa
            // berselisih tanpa ada yang tahu mana yang benar.
            if ($baris !== []) {
                $data['total'] = array_sum(array_map(fn ($b) => $this->totalBaris($b), $baris));
            }

            // Setelah total final: nominal jasa ketua diturunkan dari total itu,
            // bukan dari angka kiriman klien.
            $data = $this->terapkanPotonganAnggota($data);
            $data = $this->terapkanJasaKetua($data);

            $this->periksaPotongan($data);

            $header = TransactionHeader::create($data);

            foreach ($baris as $b) {
                // `_id` hanya penanda internal validateRows (dipakai update);
                // bukan kolom, jadi jangan ikut masuk mass assignment.
                unset($b['_id']);
                $header->transactions()->create($b);
            }

            return response()->json(
                $this->transform($header->loadCount('transactions'), true),
                201
            );
        });
    }

    /**
     * Validasi & normalkan rincian yang ikut dikirim bersama header.
     *
     * Pesan galatnya menyebut nomor baris supaya pengguna tahu baris mana di
     * formnya yang bermasalah.
     */
    private function validateRows(Request $request, ?TransactionHeader $header = null): array
    {
        $request->validate([
            'transactions' => ['nullable', 'array'],
            // Diisi saat MENGEDIT: menandai baris ini sudah ada, jadi ia
            // diperbarui di tempat alih-alih dibuat ulang. Tanpa itu baris lama
            // terhapus lalu dibuat lagi, dan periode yang sama akan ditolak
            // sendiri oleh pemeriksaan duplikat (cakupannya `withTrashed`).
            'transactions.*.uuid' => ['nullable', 'string'],
            'transactions.*.member_id' => ['required', 'integer', 'exists:members,id'],
            'transactions.*.rate_id' => ['required', 'integer', 'exists:rates,id'],
            // Wajib-tidaknya ditentukan `fee_type` tarif barisnya, diperiksa
            // periodeUntukTarif() di bawah — aturan validasi biasa tidak bisa
            // melihat isi tabel `rates`.
            'transactions.*.payment_period' => ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{4}$/'],
            'transactions.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'transactions.*.discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ], [
            'transactions.*.member_id.required' => 'Anggota pada baris :position wajib dipilih.',
            'transactions.*.member_id.exists' => 'Anggota pada baris :position tidak ada di master.',
            'transactions.*.rate_id.required' => 'Tarif pada baris :position wajib dipilih.',
            'transactions.*.rate_id.exists' => 'Tarif pada baris :position tidak ada di master.',
            'transactions.*.payment_period.regex' => 'Periode pada baris :position harus berformat MM/YYYY.',
            'transactions.*.amount.required' => 'Nominal pada baris :position wajib diisi.',
        ]);

        // Baris yang SUDAH ada pada kuitansi ini, dipetakan dari uuid-nya.
        // Dibatasi ke kuitansi ini supaya uuid milik kuitansi lain tidak bisa
        // dipakai untuk menarik barisnya ke sini.
        $idPerUuid = $header
            ? $header->transactions()->pluck('id', 'uuid')
            : collect();

        $hasil = [];
        $terpakai = [];

        foreach ($request->input('transactions', []) as $i => $baris) {
            $nomor = $i + 1;
            $field = "transactions.{$i}";
            $idLama = isset($baris['uuid']) ? ($idPerUuid[$baris['uuid']] ?? null) : null;

            $data = [
                'member_id' => (int) $baris['member_id'],
                'rate_id' => (int) $baris['rate_id'],
                'amount' => $baris['amount'],
                'discount' => $baris['discount'] ?? 0,
            ] + $this->periodeUntukTarif(
                $baris['payment_period'] ?? null,
                (int) $baris['rate_id'],
                $field
            );

            $this->periksaDiskon($data, $field);

            // Bentrok di dalam kiriman yang sama tidak akan tertangkap
            // pengecekan database — barisnya belum tersimpan saat baris
            // berikutnya diperiksa.
            //
            // Baris tarif sekali bayar dilewati: periodenya null, jadi seluruh
            // baris semacam itu akan punya kunci yang sama dan saling menuduh
            // duplikat, padahal pungutan sekali bayar memang boleh berulang.
            if ($data['month'] !== null) {
                $kunci = $data['member_id'].'-'.$data['rate_id'].'-'.$data['month'].'-'.$data['year'];

                if (isset($terpakai[$kunci])) {
                    throw ValidationException::withMessages([
                        $field => "Baris {$nomor} mengulang anggota, tarif, dan periode yang sama dengan baris {$terpakai[$kunci]}.",
                    ]);
                }

                $terpakai[$kunci] = $nomor;
            }

            // Baris yang sedang diedit harus mengabaikan dirinya sendiri —
            // kalau tidak, menyimpan ulang tanpa mengubah periode pun ditolak
            // sebagai duplikat.
            $this->periksaDuplikatPeriode($data, $field, $idLama);

            $data['_id'] = $idLama;
            $hasil[] = $data;
        }

        return $hasil;
    }

    /**
     * Kembalikan kuitansi ke keadaan BELUM DIBAYAR.
     *
     * Rincian iurannya dilepas dari kuitansi (`transaction_header_id` = null)
     * sehingga kembali berdiri sebagai tagihan yang menunggu pembayaran, lalu
     * kuitansinya sendiri di-soft-delete. Dipakai saat kuitansi terlanjur
     * dibuat salah — nominal keliru, anggotanya tertukar, atau uangnya ternyata
     * belum diterima.
     *
     * Berbeda dari `destroy` yang hanya menghapus kuitansinya: di sana rincian
     * SENGAJA tetap menempel supaya kaitannya pulih bila kuitansi di-restore.
     * Reset justru memutus kaitan itu, karena tujuannya membebaskan rincian
     * untuk dibuatkan kuitansi baru.
     *
     * Rincian TIDAK ikut dihapus: periodenya sudah tercatat sebagai tagihan
     * anggota, dan menghapusnya berarti membuang riwayat iuran yang sebenarnya
     * sah. Karena itu pula periode yang sama tetap ditolak bila diinput lagi.
     *
     * Keduanya dalam satu transaksi database: kuitansi yang terhapus sementara
     * rinciannya masih menempel akan jadi tagihan yang tak bisa ditagih lagi.
     */
    public function reset(TransactionHeader $transaksiHeader)
    {
        try {
            $dilepas = DB::transaction(function () use ($transaksiHeader) {
                $jumlah = $transaksiHeader->transactions()->count();

                // update() massal, bukan satu per satu: tidak ada kolom audit
                // yang perlu diisi di sini, dan satu kuitansi bisa memuat
                // ratusan rincian.
                $transaksiHeader->transactions()->update(['transaction_header_id' => null]);

                $transaksiHeader->delete();

                return $jumlah;
            });

            return response()->json([
                'message' => "Kuitansi direset. {$dilepas} rincian kembali menjadi tagihan yang belum dibayar.",
                'released' => $dilepas,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Tandai kuitansi SUDAH DIPERIKSA: `validation_at` + `validation_by` diisi.
     *
     * Sekali saja. Kuitansi yang sudah divalidasi ditolak di sini, bukan
     * diam-diam ditimpa — kalau nama & waktu pemeriksa pertama bisa tergeser
     * oleh klik kedua siapa pun, jejaknya tidak lagi bisa dipakai menelusuri
     * siapa yang sebenarnya memeriksa.
     *
     * Isi kuitansinya sendiri TIDAK dikunci: validasi di sini adalah jejak
     * pemeriksaan, bukan penguncian data. Kalau nanti kuitansi tervalidasi
     * perlu dilarang diedit, aturannya ditambahkan di `update()`/`destroy()`,
     * bukan diselipkan ke sini.
     */
    public function validasi(TransactionHeader $transaksiHeader)
    {
        if ($transaksiHeader->validation_at !== null) {
            return response()->json([
                'message' => 'Kuitansi ini sudah divalidasi oleh '
                    .($transaksiHeader->validation_by ?: 'pengguna lain').'.',
            ], 422);
        }

        try {
            $transaksiHeader->forceFill([
                'validation_at' => now(),
                // Nama pengguna, bukan id: mengikuti pola kolom audit di
                // seluruh proyek ini agar tidak ikut berubah bila akunnya
                // di-rename atau dihapus.
                'validation_by' => auth()->user()?->name,
            ])->save();

            return response()->json([
                'message' => "Kuitansi {$transaksiHeader->transaction_number} berhasil divalidasi.",
                'data' => $this->transform($transaksiHeader->loadCount('transactions')),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Batalkan validasi: `validation_at` & `validation_by` dikosongkan lagi.
     *
     * Endpoint TERPISAH dari `update()`, bukan sekadar field yang boleh dikirim
     * saat mengedit. Membuka kunci kuitansi adalah keputusan sendiri — kalau ia
     * bisa ikut menumpang pada sebuah edit, kuncinya terbuka sebagai efek
     * samping dan tidak ada yang menyadarinya.
     *
     * Jejak pemeriksa lama TIDAK disimpan ke mana-mana: begitu dibuka, kuitansi
     * kembali ke keadaan belum diperiksa seutuhnya, dan validasi berikutnya
     * mencatat nama & waktu yang baru.
     */
    public function batalValidasi(TransactionHeader $transaksiHeader)
    {
        if ($transaksiHeader->validation_at === null) {
            return response()->json([
                'message' => "Kuitansi {$transaksiHeader->transaction_number} memang belum divalidasi.",
            ], 422);
        }

        try {
            $transaksiHeader->forceFill([
                'validation_at' => null,
                'validation_by' => null,
            ])->save();

            return response()->json([
                'message' => "Validasi kuitansi {$transaksiHeader->transaction_number} dibatalkan. Kuitansi bisa diubah & dihapus lagi.",
                'data' => $this->transform($transaksiHeader->loadCount('transactions')),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(TransactionHeader $transaksiHeader)
    {
        return response()->json(
            $this->transform($transaksiHeader->loadCount('transactions'), true)
        );
    }

    public function update(Request $request, TransactionHeader $transaksiHeader)
    {
        if ($tolakan = $this->tolakBilaTervalidasi($transaksiHeader, 'diubah')) {
            return $tolakan;
        }

        $data = $this->validateData($request, $transaksiHeader);

        // Rincian hanya disentuh bila memang dikirim. Pembaruan yang hanya
        // mengubah header (mis. cara bayar) tidak perlu ikut mengirim seluruh
        // rinciannya, dan diamnya field ini TIDAK boleh diartikan "kosongkan".
        $denganRincian = $request->has('transactions');
        $baris = $denganRincian ? $this->validateRows($request, $transaksiHeader) : [];

        if ($denganRincian && $baris === []) {
            throw ValidationException::withMessages([
                'transactions' => 'Kuitansi harus punya minimal satu rincian.',
            ]);
        }

        // Nomor yang sudah terbit tidak boleh terhapus jadi kosong saat diedit.
        if (empty($data['transaction_number'])) {
            unset($data['transaction_number']);
        }

        return DB::transaction(function () use ($transaksiHeader, $data, $baris, $denganRincian) {
            if ($denganRincian) {
                // Total mengikuti jumlah rinciannya, bukan angka kiriman klien —
                // aturan yang sama dengan store(). Tanpa ini header dan rincian
                // bisa berselisih tanpa ada yang tahu mana yang benar.
                $data['total'] = array_sum(array_map(fn ($b) => $this->totalBaris($b), $baris));

                $this->sinkronkanRincian($transaksiHeader, $baris);
            }

            // Persentase atau totalnya bisa berubah, jadi nominal jasa ketua selalu
            // dihitung ulang — kalau tidak, nominalnya membeku di angka lama dan
            // tidak lagi cocok dengan persentase yang tercatat di kuitansi ini.
            $data = $this->terapkanPotonganAnggota($data);
            $data = $this->terapkanJasaKetua($data);

            $this->periksaPotongan($data);

            $transaksiHeader->update($data);

            return response()->json(
                $this->transform($transaksiHeader->fresh()->loadCount('transactions'), true)
            );
        });
    }

    /**
     * Samakan isi rincian kuitansi dengan yang dikirim form edit.
     *
     * Baris yang membawa `uuid` DIPERBARUI di tempat, bukan dihapus lalu dibuat
     * ulang. Bedanya bukan sekadar rapi: pemeriksaan duplikat periode
     * bercakupan `withTrashed()`, jadi baris yang dihapus lalu dibuat lagi
     * dengan periode yang sama akan ditolak oleh bekasnya sendiri.
     *
     * Baris yang tidak lagi ada di kiriman dilepas SATU PER SATU lewat model,
     * bukan mass delete: `HasAuditColumns::delete()` yang mengisi `deleted_by`
     * hanya berjalan pada instance model, dan tanpa kolom itu barisnya tidak
     * terhitung terhapus sama sekali (global scope `active` membacanya).
     *
     * @param  array<int,array<string,mixed>>  $baris
     */
    private function sinkronkanRincian(TransactionHeader $header, array $baris): void
    {
        $dipertahankan = [];

        foreach ($baris as $b) {
            $id = $b['_id'] ?? null;
            unset($b['_id']);

            $lama = $id !== null
                ? $header->transactions()->whereKey($id)->first()
                : null;

            if ($lama) {
                $lama->update($b);
                $dipertahankan[] = $lama->id;

                continue;
            }

            $dipertahankan[] = $header->transactions()->create($b)->id;
        }

        $header->transactions()
            ->when($dipertahankan !== [], fn ($q) => $q->whereNotIn('id', $dipertahankan))
            ->get()
            ->each(fn ($row) => $row->delete());
    }

    public function destroy(TransactionHeader $transaksiHeader)
    {
        if ($tolakan = $this->tolakBilaTervalidasi($transaksiHeader, 'dihapus')) {
            return $tolakan;
        }

        // Soft delete. Rincian tidak ikut terhapus dan `transaction_header_id`
        // sengaja dibiarkan terisi: relasinya otomatis terbaca null karena
        // global scope menyaring header yang sudah dihapus, dan kaitannya pulih
        // utuh bila header ini di-restore. Mengosongkan kolomnya justru membuat
        // restore tidak ada gunanya.
        $transaksiHeader->delete();

        return response()->json(['message' => 'Header transaksi dihapus.']);
    }

    /**
     * Kuitansi yang SUDAH divalidasi tidak boleh lagi diubah atau dihapus.
     *
     * Jejak pemeriksaan tidak ada artinya kalau isi kuitansinya masih bisa
     * bergeser sesudahnya: nama pemeriksa tetap menempel pada angka yang bukan
     * lagi yang ia periksa.
     *
     * Ditegakkan DI SERVER, bukan sekadar menyembunyikan tombolnya di halaman —
     * tombol yang hilang hanya menutup jalan yang lewat antarmuka.
     *
     * Untuk mengeditnya lagi, kuncinya dibuka lebih dulu lewat `batalValidasi()` —
     * endpoint tersendiri, supaya membuka kunci jadi keputusan sadar dan bukan efek
     * samping yang menumpang pada sebuah edit.
     */
    private function tolakBilaTervalidasi(TransactionHeader $header, string $tindakan): ?JsonResponse
    {
        if ($header->validation_at === null) {
            return null;
        }

        return response()->json([
            'message' => "Kuitansi {$header->transaction_number} sudah divalidasi"
                .($header->validation_by ? ' oleh '.$header->validation_by : '')
                .", jadi tidak bisa {$tindakan} lagi.",
        ], 422);
    }

    /**
     * `$header` diisi saat update supaya nomornya sendiri tidak terhitung
     * sebagai duplikat.
     */
    private function validateData(Request $request, ?TransactionHeader $header = null): array
    {
        $data = $request->validate([
            'transaction_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('transaction_headers', 'transaction_number')->ignore($header?->id),
            ],
            // Tanggal uang diterima. WAJIB walau kolomnya nullable di database:
            // nullable hanya supaya migrasi tidak memaksakan nilai palsu pada
            // baris lama, sedangkan kuitansi baru selalu punya tanggalnya.
            'date' => ['required', 'date'],
            'transaction_type' => ['required', Rule::in(TransactionHeader::TRANSACTION_TYPES)],
            'total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            // Potongan anggota diterima sebagai NILAI KETIK + SATUAN, bukan
            // rupiah jadi. Nominal rupiahnya dihitung di `terapkanPotonganAnggota()`
            // supaya angka di layar dan yang tersimpan tidak pernah berbeda —
            // sama seperti jasa ketua kelompok.
            'member_deduction_type' => ['nullable', Rule::in(TransactionHeader::DEDUCTION_TYPES)],
            'member_deduction_input' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            // Persentase, bukan rupiah. Dua kolom rupiah di sebelahnya
            // (`group_leader_deduction` & `group_leader_fee`) TIDAK diterima
            // dari klien — keduanya dihitung dari persentase ini di
            // `terapkanJasaKetua()` supaya angka di layar dan yang tersimpan
            // tidak pernah berbeda.
            'group_leader_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'payment_method' => ['required', Rule::in(TransactionHeader::PAYMENT_METHODS)],
        ], [
            'transaction_number.unique' => 'No. Transaksi ":input" sudah dipakai.',
            'date.required' => 'Tanggal transaksi wajib diisi.',
            'date.date' => 'Tanggal transaksi tidak valid.',
            'transaction_type.required' => 'Jenis transaksi wajib dipilih.',
            'transaction_type.in' => 'Jenis transaksi hanya boleh kelompok atau pribadi.',
            'total.required' => 'Total wajib diisi.',
            'total.numeric' => 'Total harus berupa angka.',
            'member_deduction_type.in' => 'Satuan potongan anggota hanya boleh rupiah atau persen.',
            'member_deduction_input.numeric' => 'Potongan anggota harus berupa angka.',
            'group_leader_fee_percent.numeric' => 'Potongan ketua kelompok harus berupa angka.',
            'group_leader_fee_percent.max' => 'Potongan ketua kelompok tidak boleh lebih dari 100%.',
            'payment.required' => 'Pembayaran wajib diisi.',
            'payment.numeric' => 'Pembayaran harus berupa angka.',
            'payment_method.required' => 'Cara bayar wajib dipilih.',
            'payment_method.in' => 'Cara bayar hanya boleh transfer, tunai, atau lain-lain.',
        ]);

        $data['member_deduction_type'] = $data['member_deduction_type'] ?? 'amount';
        $data['member_deduction_input'] = $data['member_deduction_input'] ?? 0;
        $data['group_leader_fee_percent'] = $data['group_leader_fee_percent'] ?? 0;

        // Persen di atas 100 berarti potongannya melebihi seluruh tagihan —
        // hampir selalu salah satuan (mengetik 25000 lalu memilih "%").
        if ($data['member_deduction_type'] === 'percent' && (float) $data['member_deduction_input'] > 100) {
            throw ValidationException::withMessages([
                'member_deduction_input' => 'Potongan anggota dalam persen tidak boleh lebih dari 100%.',
            ]);
        }

        // Potongan & jasa ketua kelompok hanya berlaku pada setoran kelompok.
        // Dinolkan di sini, bukan sekadar disembunyikan di form: form yang
        // menyembunyikan field tidak menghalangi permintaan yang disusun
        // sendiri, dan angka nyasar itu tetap akan menggeser `balance`.
        if ($data['transaction_type'] === 'pribadi') {
            $data['group_leader_fee_percent'] = 0;
        }

        return $data;
    }

    /**
     * Turunkan nominal rupiah potongan anggota dari nilai ketik + satuannya.
     *
     * Dihitung di server dari `total` final — bukan diterima dari klien —
     * supaya nominalnya tidak bisa berselisih dengan persentase yang tercatat
     * di kuitansi yang sama.
     *
     * Hasilnya disimpan sebagai rupiah dan itulah yang mengikat: kalau yang
     * disimpan cuma persennya, potongan ikut bergeser begitu rincian kuitansi
     * diedit dan kuitansi yang sudah tercetak jadi tidak cocok lagi.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function terapkanPotonganAnggota(array $data): array
    {
        $nilai = (float) $data['member_deduction_input'];

        $data['member_deduction'] = $data['member_deduction_type'] === 'percent'
            ? round((float) $data['total'] * $nilai / 100, 2)
            : round($nilai, 2);

        return $data;
    }

    /**
     * Turunkan jasa ketua kelompok dari persentasenya.
     *
     * Jasa ketua adalah CATATAN HAK ketua — dipakai laporan & pembayaran komisi
     * — dan TIDAK mengurangi setoran yang harus diterima. Karena itu
     * `group_leader_fee` diisi nominalnya sedangkan `group_leader_deduction`
     * dinolkan; hanya yang kedua yang masuk hitungan `balance`, lihat
     * TransactionHeader::getBalanceAttribute().
     *
     * Sebelumnya keduanya diisi nominal yang sama sehingga komisi ketua ikut
     * memotong setoran. Itu diubah bersamaan dengan dipisahkannya potongan ketua
     * ke bagiannya sendiri di form transaksi: yang disetorkan anggota tidak
     * berkurang hanya karena ketua berhak atas komisi, dan komisinya dibayarkan
     * lewat kas — bukan dengan menahan uang setoran.
     *
     * Kolomnya sengaja tetap diisi 0 dan bukan dihapus: kuitansi LAMA masih
     * menyimpan nominal di sana, dan `balance` mereka harus tetap terbaca seperti
     * saat dibuat.
     *
     * Dihitung di server dari `total` final — bukan diterima dari klien —
     * supaya nominalnya tidak bisa berselisih dengan persentase yang tercatat
     * di kuitansi yang sama.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function terapkanJasaKetua(array $data): array
    {
        $nominal = round((float) $data['total'] * (float) $data['group_leader_fee_percent'] / 100, 2);

        $data['group_leader_deduction'] = 0;
        $data['group_leader_fee'] = $nominal;

        return $data;
    }

    /**
     * Potongan gabungan tidak boleh melebihi total — hasilnya tagihan negatif,
     * yang hampir selalu salah ketik dan lebih baik ditolak daripada tersimpan
     * diam-diam.
     *
     * Dipanggil setelah `total` final diketahui: bila ada rincian, totalnya
     * dihitung dari rincian itu dan bukan dari angka kiriman klien.
     */
    private function periksaPotongan(array $data): void
    {
        $potongan = (float) $data['member_deduction'] + (float) $data['group_leader_deduction'];

        if ($potongan > (float) $data['total']) {
            throw ValidationException::withMessages([
                'member_deduction' => 'Potongan anggota + potongan ketua kelompok tidak boleh melebihi total.',
            ]);
        }
    }

    /**
     * Cetak biling kuitansi ke PDF.
     *
     * Dikembalikan inline (`stream`), bukan sebagai unduhan: frontend
     * menampilkannya di iframe sebagai pratinjau dan menyediakan tombol unduh
     * sendiri — pola yang sama dengan cetak asesmen clinical pathway.
     *
     * HANYA untuk kuitansi yang sudah divalidasi. Biling adalah dokumen final
     * yang dipegang anggota; kuitansi yang belum diperiksa masih bisa berubah
     * isinya, dan lembar tercetak yang tidak lagi cocok dengan datanya justru
     * lebih merepotkan daripada tidak punya lembar sama sekali. Pemeriksaan ini
     * ada di server, bukan cuma menyembunyikan tombolnya di frontend: URL-nya
     * bisa ditebak dari nomor kuitansi mana pun.
     */
    public function biling(TransactionHeader $transaksiHeader): Response|JsonResponse
    {
        if ($transaksiHeader->validation_at === null) {
            return response()->json([
                'message' => 'Kuitansi ini belum divalidasi, jadi bilingnya belum bisa dicetak.',
            ], 422);
        }

        $rincian = $transaksiHeader->transactions()
            ->with(['member:id,member_number,name'])
            ->get();

        $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');

        $baris = $this->barisBilingPerAnggota($transaksiHeader, $rincian);

        $tagihan = (float) $transaksiHeader->total
            - (float) $transaksiHeader->member_deduction
            - (float) $transaksiHeader->group_leader_deduction;

        $selisih = round((float) $transaksiHeader->balance, 2);

        $qr = null;

        if ($transaksiHeader->validation_by) {
            // `encoding('UTF-8')` wajib: bawaannya ISO-8859-1, dan nama pemeriksa
            // yang memuat satu saja huruf di luar Latin-1 membuat encoder-nya
            // melempar WriterException — cetak biling gagal total gara-gara QR.
            //
            // Data URI: dompdf tidak bisa mengambil berkas dari luar dokumen.
            $svg = QrCode::format('svg')
                ->encoding('UTF-8')
                ->size(90)
                ->margin(0)
                ->generate(
                    "Kuitansi {$transaksiHeader->transaction_number} - diverifikasi oleh {$transaksiHeader->validation_by}"
                );
            $qr = 'data:image/svg+xml;base64,'.base64_encode($svg);
        }

        $pdf = Pdf::loadView('pdf.nafsul_biling', [
            'header' => $transaksiHeader,
            'tanggal' => optional($transaksiHeader->date)->translatedFormat('d F Y') ?? '—',
            'divalidasi' => optional($transaksiHeader->validation_at)->translatedFormat('d F Y H:i') ?? '—',
            'qr' => $qr,
            'baris' => $baris,
            'uang' => [
                'total' => $rupiah($transaksiHeader->total),
                'member_deduction' => $rupiah($transaksiHeader->member_deduction),
                'group_leader_deduction' => $rupiah($transaksiHeader->group_leader_deduction),
                // Yang seharusnya diterima setelah potongan. Dihitung ulang di
                // sini dengan rumus yang sama dengan `balance`, karena accessor
                // itu langsung mengembalikan SELISIHNYA terhadap `payment` —
                // angka antaranya tidak pernah keluar.
                'tagihan' => $rupiah($tagihan),
                'payment' => $rupiah($transaksiHeader->payment),
            ],
            // `balance` = seharusnya − dibayar. Positif berarti masih kurang
            // bayar, negatif berarti lebih bayar; nol tidak ditampilkan sama
            // sekali karena tidak ada yang perlu diberitahukan.
            'selisih' => [
                'nilai' => $selisih,
                'label' => $selisih > 0 ? 'Kurang Bayar' : 'Lebih Bayar',
                'rupiah' => $rupiah(abs($selisih)),
            ],
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('biling-'.$transaksiHeader->transaction_number.'.pdf');
    }

    /**
     * Rincian kuitansi → SATU BARIS PER ANGGOTA untuk lembar biling.
     *
     * Biling adalah lembar yang dipegang penyetor, bukan salinan basis data:
     * seorang anggota yang membayar 12 bulan sekaligus cukup ditulis satu baris
     * "01/2026 – 12/2026", bukan dua belas baris yang isinya berulang. Rincian
     * per bulan tetap ada di aplikasi bagi yang perlu menelusurinya.
     *
     * @param  Collection<int, Transaction>  $rincian
     * @return array<int, array<string, mixed>>
     */
    private function barisBilingPerAnggota(TransactionHeader $header, $rincian): array
    {
        $perAnggota = $rincian->groupBy('member_id');
        $idAnggota = $perAnggota->keys()->all();

        if ($idAnggota === []) {
            return [];
        }

        /**
         * Periode PALING AWAL yang pernah tercatat atas nama tiap anggota —
         * seluruh riwayatnya, bukan hanya kuitansi ini.
         *
         * Dipakai menentukan kolom Kunjungan: "B" bila kuitansi inilah yang
         * memuat iuran paling awal anggota itu, "L" bila ia sudah punya iuran
         * yang lebih dulu. Dasarnya sengaja periode TERAWAL, bukan sekadar
         * "punya transaksi lain": kalau pakai yang kedua, memasukkan iuran bulan
         * berikutnya akan mengubah lembar yang sudah tercetak dari B jadi L.
         */
        $awalGlobal = Transaction::whereIn('member_id', $idAnggota)
            ->whereNotNull('month')
            ->selectRaw('member_id, MIN(year * 100 + month) AS awal')
            ->groupBy('member_id')
            ->pluck('awal', 'member_id');

        // Cadangan untuk anggota yang seluruh iurannya tarif sekali bayar:
        // mereka tidak punya periode sama sekali, jadi tidak ada yang bisa
        // dibandingkan — yang tersisa cuma "punya iuran di luar kuitansi ini".
        $adaDiLuar = Transaction::whereIn('member_id', $idAnggota)
            ->where(fn ($q) => $q->whereNull('transaction_header_id')
                ->orWhere('transaction_header_id', '!=', $header->id))
            ->selectRaw('member_id, COUNT(*) AS jml')
            ->groupBy('member_id')
            ->pluck('jml', 'member_id');

        $baris = [];

        foreach ($perAnggota as $memberId => $milik) {
            $periode = $milik->filter(fn ($t) => $t->month !== null)
                ->map(fn ($t) => (int) $t->year * 100 + (int) $t->month)
                ->sort()
                ->values();

            if ($periode->isEmpty()) {
                $baru = ((int) ($adaDiLuar[$memberId] ?? 0)) === 0;
            } else {
                $baru = (int) ($awalGlobal[$memberId] ?? PHP_INT_MAX) >= $periode->first();
            }

            $anggota = $milik->first()->member;

            $baris[] = [
                'no_anggota' => $anggota?->member_number,
                'nama' => $anggota?->name ?? '—',
                'periode' => $this->rentangPeriode($periode->first(), $periode->last()),
                'kunjungan' => $baru ? 'B' : 'L',
                'jumlah_nilai' => $milik->sum(fn ($t) => (float) $t->total),
            ];
        }

        return $this->bagiPotongan($baris, (float) $header->member_deduction);
    }

    /** `202601`, `202612` → `"01/2026 – 12/2026"`. Sama → satu periode saja. */
    private function rentangPeriode(?int $awal, ?int $akhir): string
    {
        if ($awal === null) {
            return 'sekali bayar';
        }

        $tulis = fn (int $p) => str_pad((string) ($p % 100), 2, '0', STR_PAD_LEFT).'/'.intdiv($p, 100);

        return $awal === $akhir ? $tulis($awal) : $tulis($awal).' – '.$tulis($akhir);
    }

    /**
     * Bagi potongan anggota — satu angka di tingkat kuitansi — ke baris-barisnya,
     * sebanding dengan jumlah masing-masing.
     *
     * `member_deduction` memang tersimpan per KUITANSI, bukan per anggota, jadi
     * angka per baris di sini adalah pembagian, bukan data tersimpan. Sisa
     * pembulatannya ditimpakan ke baris terakhir supaya kolomnya berjumlah
     * PERSIS sama dengan potongan di ringkasan — lembar biling yang kolomnya
     * tidak menjumlah adalah lembar yang akan dipertanyakan.
     *
     * Dibagi dalam RUPIAH BULAT, bukan dua desimal. Lembar ini mencetak rupiah
     * bulat, jadi pembagian sen hanya akan hilang di pembulatan tampilan dan
     * membuat kolomnya meleset justru karena sisanya sudah "diselesaikan" pada
     * angka yang tidak pernah tercetak.
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int, array<string, mixed>>
     */
    private function bagiPotongan(array $baris, float $potongan): array
    {
        $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $total = array_sum(array_column($baris, 'jumlah_nilai'));
        $sasaran = (int) round($potongan);
        $terbagi = 0;
        $akhir = count($baris) - 1;

        foreach ($baris as $i => $b) {
            // Baris terakhir menerima SISANYA, bukan hasil hitungnya sendiri.
            // Itu juga yang menyelamatkan keadaan total nol: tanpa ini seluruh
            // baris jadi nol dan potongannya hilang dari lembar.
            $bagian = $i === $akhir
                ? $sasaran - $terbagi
                : ($total > 0 ? (int) round($sasaran * $b['jumlah_nilai'] / $total) : 0);

            $terbagi += $bagian;

            $baris[$i]['jumlah'] = $rupiah($b['jumlah_nilai']);
            $baris[$i]['potongan'] = $rupiah($bagian);
            unset($baris[$i]['jumlah_nilai']);
        }

        return $baris;
    }

    /**
     * `balance` = yang seharusnya dibayar dikurangi yang diterima.
     * Positif berarti masih kurang bayar, negatif berarti lebih bayar.
     */
    private function transform(TransactionHeader $row, bool $denganRincian = false): array
    {
        $hasil = [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'transaction_number' => $row->transaction_number,
            // Tanggal uang diterima; `created_at` tetap dikirim terpisah sebagai
            // jejak kapan barisnya dibuat — keduanya memang bisa berbeda.
            'date' => optional($row->date)->toDateString(),
            'transaction_type' => $row->transaction_type,
            'total' => $row->total,
            'member_deduction' => $row->member_deduction,
            'member_deduction_type' => $row->member_deduction_type,
            'member_deduction_input' => $row->member_deduction_input,
            'group_leader_deduction' => $row->group_leader_deduction,
            // Persentasenya ikut dikirim supaya form yang membuka kuitansi lama
            // menampilkan angka yang diketik petugas (10), bukan hasil hitung
            // mundur dari rupiah yang bisa meleset karena pembulatan.
            'group_leader_fee_percent' => $row->group_leader_fee_percent,
            'group_leader_fee' => $row->group_leader_fee,
            'payment' => $row->payment,
            'payment_method' => $row->payment_method,
            // Jejak pemeriksaan. `validation_at` null = belum divalidasi —
            // itulah satu-satunya penanda statusnya, tidak ada kolom boolean
            // terpisah yang bisa menyimpang darinya.
            'validation_at' => optional($row->validation_at)->toDateTimeString(),
            'validation_by' => $row->validation_by,
            'balance' => $row->balance,
            'transactions_count' => $row->transactions_count ?? 0,
            'created_at' => optional($row->created_at)->toDateTimeString(),
            // Jejak penghapusan. Pada pemanggilan biasa ketiganya SELALU null —
            // global scope `active` menyaring baris terhapus — dan baru berisi
            // saat sengaja diambil lewat `withTrashed()`. Tetap dikirim supaya
            // bentuk responsnya satu macam, tidak berubah tergantung cakupan.
            'disabled' => (bool) $row->disabled,
            'deleted_at' => optional($row->deleted_at)->toDateTimeString(),
            'deleted_by' => $row->deleted_by,
        ];

        if ($denganRincian) {
            $hasil['transactions'] = $row->transactions()
                ->with(['member:id,member_number,name', 'rate:id,code,name'])
                ->orderBy('year')
                ->orderBy('month')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'uuid' => $t->uuid,
                    'member_id' => $t->member_id,
                    'member_number' => $t->member?->member_number,
                    'member_name' => $t->member?->name,
                    'rate_id' => $t->rate_id,
                    'rate_name' => $t->rate?->name,
                    'payment_period' => $t->payment_period,
                    'amount' => $t->amount,
                    'discount' => $t->discount,
                    'total' => $t->total,
                    'disabled' => (bool) $t->disabled,
                    'deleted_at' => optional($t->deleted_at)->toDateTimeString(),
                    'deleted_by' => $t->deleted_by,
                ]);
        }

        return $hasil;
    }
}
