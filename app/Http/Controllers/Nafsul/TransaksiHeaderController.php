<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        if ($dari = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dari);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $sampai);
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
    private function validateRows(Request $request): array
    {
        $request->validate([
            'transactions' => ['nullable', 'array'],
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

        $hasil = [];
        $terpakai = [];

        foreach ($request->input('transactions', []) as $i => $baris) {
            $nomor = $i + 1;
            $field = "transactions.{$i}";

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

            $this->periksaDuplikatPeriode($data, $field);

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

    public function show(TransactionHeader $transaksiHeader)
    {
        return response()->json(
            $this->transform($transaksiHeader->loadCount('transactions'), true)
        );
    }

    public function update(Request $request, TransactionHeader $transaksiHeader)
    {
        $data = $this->validateData($request, $transaksiHeader);

        // Nomor yang sudah terbit tidak boleh terhapus jadi kosong saat diedit.
        if (empty($data['transaction_number'])) {
            unset($data['transaction_number']);
        }

        // Persentase atau totalnya bisa berubah, jadi nominal jasa ketua selalu
        // dihitung ulang — kalau tidak, nominalnya membeku di angka lama dan
        // tidak lagi cocok dengan persentase yang tercatat di kuitansi ini.
        $data = $this->terapkanPotonganAnggota($data);
        $data = $this->terapkanJasaKetua($data);

        $this->periksaPotongan($data);

        $transaksiHeader->update($data);

        return response()->json(
            $this->transform($transaksiHeader->fresh()->loadCount('transactions'))
        );
    }

    public function destroy(TransactionHeader $transaksiHeader)
    {
        // Soft delete. Rincian tidak ikut terhapus dan `transaction_header_id`
        // sengaja dibiarkan terisi: relasinya otomatis terbaca null karena
        // global scope menyaring header yang sudah dihapus, dan kaitannya pulih
        // utuh bila header ini di-restore. Mengosongkan kolomnya justru membuat
        // restore tidak ada gunanya.
        $transaksiHeader->delete();

        return response()->json(['message' => 'Header transaksi dihapus.']);
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
            'payment_method.in' => 'Cara bayar hanya boleh transfer atau cash.',
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
     * Turunkan potongan & jasa ketua kelompok dari persentasenya.
     *
     * Ketua kelompok menahan komisinya dari uang yang ia kumpulkan, jadi satu
     * angka yang sama dicatat dua kali dengan peran berbeda: `group_leader_deduction`
     * MENGURANGI setoran, `group_leader_fee` merekam HAK ketua untuk keperluan
     * laporan/pembayaran komisi.
     *
     * Hanya yang pertama masuk hitungan `balance` — lihat
     * TransactionHeader::getBalanceAttribute().
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

        $data['group_leader_deduction'] = $nominal;
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
     * `balance` = yang seharusnya dibayar dikurangi yang diterima.
     * Positif berarti masih kurang bayar, negatif berarti lebih bayar.
     */
    private function transform(TransactionHeader $row, bool $denganRincian = false): array
    {
        $hasil = [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'transaction_number' => $row->transaction_number,
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
            'balance' => $row->balance,
            'transactions_count' => $row->transactions_count ?? 0,
            'created_at' => optional($row->created_at)->toDateTimeString(),
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
                ]);
        }

        return $hasil;
    }
}
