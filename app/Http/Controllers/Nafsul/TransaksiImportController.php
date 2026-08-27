<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Rate;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Impor transaksi iuran dari file Excel.
 *
 * Satu file, **dua sheet data**, sekali unggah:
 *
 * | Sheet       | Isi                        | Dikirim sebagai |
 * | ----------- | -------------------------- | --------------- |
 * | `Kuitansi`  | satu baris per kuitansi    | `headers`       |
 * | `Rincian`   | satu baris per iuran       | `rows`          |
 *
 * Kolom WAJIB sheet `Kuitansi`: `Kode Kuitansi`, `Tanggal`, `Jenis`, `Dibayar`,
 * `Metode`. Sisanya boleh kosong dan diperlakukan sebagai nol.
 *
 * Kolom WAJIB sheet `Rincian`: `Kode Kuitansi`, `No. Anggota`, `Kode Tarif`.
 * `Nominal` boleh kosong — server memakai harga tarifnya, supaya petugas tidak
 * perlu menyalin angka yang sama ratusan kali. `Periode` mengikuti sifat
 * tarifnya: wajib untuk tarif berulang, harus KOSONG untuk tarif sekali bayar.
 *
 * Keduanya dihubungkan kolom `Kode Kuitansi`. Kode itu hanya berlaku di dalam
 * file — dipakai untuk merangkai, bukan disimpan; nomor kuitansi yang sebenarnya
 * tetap dibuat server (`generateNumber`).
 *
 * Bentuk dua sheet dipilih daripada satu sheet yang mengulang kolom kuitansi di
 * tiap baris rincian: pengulangan itu membuat satu kuitansi bisa menyebut dua
 * nilai "Dibayar" yang berbeda, dan tidak ada cara benar untuk memilih salah
 * satunya. Dengan sheet terpisah, keadaan itu tidak bisa terjadi sama sekali.
 *
 * Seorang anggota yang membayar 12 bulan sekaligus ditulis 12 baris di sheet
 * Rincian + 1 baris di sheet Kuitansi, dan menghasilkan satu kuitansi berisi 12
 * rincian — bukan 12 kuitansi terpisah.
 *
 * Satu grup diproses sebagai satu kesatuan di dalam satu transaksi database:
 * bila ada satu rincian yang ditolak, seluruh kuitansi itu batal. Kuitansi yang
 * separuh terisi akan punya total yang tidak cocok dengan "Dibayar" di filenya,
 * dan selisih itu baru ketahuan jauh di belakang. Grup lain tidak terpengaruh.
 *
 * Dipisah dari TransaksiHeaderController yang sudah panjang: aturan impor
 * (pemetaan kode → id, penggabungan grup, pelaporan per baris) tidak dipakai
 * jalur simpan biasa sama sekali.
 */
class TransaksiImportController extends Controller
{
    use HandlesTransactionRows;

    /**
     * Batas baris per permintaan.
     *
     * Lebih longgar dari impor master (50) karena satu kuitansi bisa memuat
     * belasan bulan dikali beberapa anggota, dan frontend menjaga satu grup
     * tidak pernah terpecah antar permintaan — batch-nya justru mengikuti batas
     * grup, bukan angka bulat.
     */
    private const MAKS_BARIS = 200;

    /**
     * Nama sheet, dikirim ikut tiap baris hasil.
     *
     * Frontend memakainya untuk memisahkan galat ke sheet yang benar saat
     * menulis file "gagal impor" — kesalahan sheet Kuitansi harus mendarat di
     * sheet Kuitansi, bukan tercampur ke daftar rincian.
     */
    private const SHEET_KUITANSI = 'Kuitansi';

    private const SHEET_RINCIAN = 'Rincian';

    /**
     * Kolom sheet "Kuitansi" → kolom tabel `transaction_headers`.
     *
     * `potongan_ketua` diisi PERSENTASE (10 = 10%), bukan rupiah. Nominalnya —
     * potongan sekaligus jasa ketua — diturunkan dari persentase itu dikali
     * total rincian, jadi tidak ada kolomnya di file: angka yang bisa diketik
     * sendiri hanya akan berselisih dengan hasil hitungannya.
     */
    private const KOLOM_KUITANSI = [
        // Tanggal uang DITERIMA. Wajib — kuitansi tanpa tanggal tidak bisa
        // dipertanggungjawabkan ke buku kas, dan menebaknya dari waktu impor
        // justru memberi tanggal yang pasti salah untuk data lama.
        'tanggal' => 'date',
        'jenis' => 'transaction_type',
        'dibayar' => 'payment',
        'metode' => 'payment_method',
        'potongan_anggota' => 'member_deduction',
        'potongan_ketua' => 'group_leader_fee_percent',
    ];

    public function import(Request $request)
    {
        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAKS_BARIS],
            'rows.*' => ['array'],
            // Sheet "Kuitansi" dikirim UTUH di tiap permintaan, tidak ikut
            // dipecah bersama rincian — tiap batch butuh induk milik barisnya,
            // dan jumlahnya jauh lebih sedikit daripada rinciannya.
            'headers' => ['nullable', 'array', 'max:'.self::MAKS_BARIS],
            'headers.*' => ['array'],
        ]);

        // TAHAP 1 — SHEET KUITANSI.
        //
        // Seluruh induk divalidasi lebih dulu, sebelum satu pun baris rincian
        // disentuh. Dua alasannya:
        //
        //  - galat sheet Kuitansi jadi bisa ditunjuk ke BARIS SHEET KUITANSI-nya
        //    sendiri. Sebelumnya validasi induk menumpang di dalam pemrosesan
        //    grup, jadi kesalahan ketik di sheet Kuitansi dilaporkan sebagai
        //    kegagalan baris Rincian — petugas membetulkan sheet yang salah;
        //  - kuitansi yang isinya tidak sah tidak perlu lagi membuka transaksi
        //    database untuk rinciannya.
        $kuitansi = $this->siapkanKuitansi($payload['headers'] ?? []);

        $hasil = [];

        // TAHAP 2 — SHEET RINCIAN, memakai induk yang sudah lolos tahap 1.
        $grupRincian = $this->kelompokkan($payload['rows']);

        foreach ($grupRincian as $kode => $grup) {
            foreach ($this->prosesGrup($kode, $grup, $kuitansi) as $baris) {
                $hasil[] = $baris;
            }
        }

        // Galat sheet Kuitansi dilaporkan sebagai barisnya SENDIRI, bukan hanya
        // menempel pada rinciannya: yang perlu dibetulkan petugas ada di sheet
        // itu, dan tanpa baris ini file "gagal impor" tidak punya tempat untuk
        // menuliskan alasannya.
        //
        // Yang dilaporkan hanya kuitansi yang DIPAKAI baris rincian permintaan
        // ini, ditambah baris tanpa Kode Kuitansi — baris itu tidak mungkin
        // dipakai siapa pun, tapi tetap harus dibetulkan. Saringannya perlu
        // karena sheet Kuitansi dikirim UTUH di tiap batch: tanpa itu satu
        // kuitansi salah dilaporkan berulang, sekali per batch, dan angka
        // `gagal` menggelembung sebanyak jumlah batchnya.
        foreach ($kuitansi['galat'] as $galat) {
            if ($galat['kode'] !== '' && ! isset($grupRincian[$galat['kode']])) {
                continue;
            }

            $hasil[] = [
                'sheet' => self::SHEET_KUITANSI,
                'baris' => $galat['baris'],
                'status' => 'gagal',
                'nama' => $galat['kode'],
                'pesan' => $galat['pesan'],
            ];
        }

        // Urutan hasil mengikuti nomor baris di file, bukan urutan pemrosesan
        // per grup — daftar galat di layar harus bisa ditelusuri turun sejajar
        // dengan filenya. Sheet Kuitansi ditaruh lebih dulu karena itu pula
        // urutan pengerjaannya: betulkan induknya, baru rinciannya.
        usort($hasil, function ($a, $b) {
            $urutan = fn ($h) => $h['sheet'] === self::SHEET_KUITANSI ? 0 : 1;

            return [$urutan($a), $a['baris']] <=> [$urutan($b), $b['baris']];
        });

        $berhasil = count(array_filter($hasil, fn ($h) => $h['status'] === 'ok'));

        return response()->json([
            'berhasil' => $berhasil,
            'gagal' => count($hasil) - $berhasil,
            'hasil' => $hasil,
        ]);
    }

    /**
     * TAHAP 1 — sheet "Kuitansi" divalidasi seluruhnya, sebelum rincian.
     *
     * Hasilnya dua peta yang sama-sama dikunci kode kuitansi:
     *
     *  - `siap`  — kolom `transaction_headers` yang sudah lolos validasi;
     *  - `galat` — kuitansi yang ditolak, beserta NOMOR BARISNYA di sheet
     *    Kuitansi dan alasannya.
     *
     * Kuitansi yang masuk `galat` tidak pernah sampai ke tahap rincian: seluruh
     * rinciannya ikut dibatalkan dengan pesan yang menunjuk balik ke baris sheet
     * Kuitansi yang harus dibetulkan.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array{siap: array<string, array<string, mixed>>, galat: array<string, array{kode: string, baris: int, pesan: string}>}
     */
    private function siapkanKuitansi(array $headers): array
    {
        $siap = [];
        $galat = [];
        $barisKode = [];

        foreach ($headers as $i => $row) {
            $baris = (int) ($row['baris'] ?? $i + 2);
            $kode = trim((string) ($row['kode_kuitansi'] ?? ''));

            if ($kode === '') {
                $galat['#baris'.$baris] = [
                    'kode' => '',
                    'baris' => $baris,
                    'pesan' => 'Kode Kuitansi wajib diisi — kolom itu yang menghubungkan kuitansi ini ke sheet Rincian.',
                ];

                continue;
            }

            // Kode kembar DITOLAK, tidak lagi diam-diam memakai yang pertama.
            //
            // Dulu yang kedua dibuang tanpa suara supaya satu baris induk tidak
            // menjatuhkan seluruh impor. Sekarang kegagalannya bisa dibatasi ke
            // kuitansi itu saja, jadi tidak ada alasan menyembunyikannya: dua
            // baris berkode sama berarti salah satunya salah ketik, dan yang
            // terbuang akan hilang tanpa jejak kalau tidak dilaporkan.
            if (isset($barisKode[$kode])) {
                $galat[$kode] = [
                    'kode' => $kode,
                    'baris' => $baris,
                    'pesan' => "Kode Kuitansi \"{$kode}\" dipakai dua kali di sheet Kuitansi (baris {$barisKode[$kode]} dan {$baris}). Beri kode yang berbeda.",
                ];
                unset($siap[$kode]);

                continue;
            }

            $barisKode[$kode] = $baris;

            try {
                $siap[$kode] = $this->headerDariSheet($row);
            } catch (ValidationException $e) {
                $galat[$kode] = [
                    'kode' => $kode,
                    'baris' => $baris,
                    'pesan' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                ];
            }
        }

        return ['siap' => $siap, 'galat' => $galat];
    }

    /**
     * Kelompokkan rincian berdasarkan `kode_kuitansi`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function kelompokkan(array $rows): array
    {
        $grup = [];

        foreach ($rows as $i => $row) {
            $row['baris'] = (int) ($row['baris'] ?? $i + 1);
            $kode = trim((string) ($row['kode_kuitansi'] ?? ''));

            $grup[$kode][] = $row;
        }

        return $grup;
    }

    /**
     * Satu grup rincian + satu baris sheet Kuitansi → satu kuitansi tersimpan.
     *
     * @param  array<int, array<string, mixed>>  $grup
     * @param  array<string, array<string, mixed>>  $kuitansi
     * @return array<int, array<string, mixed>> hasil per baris
     */
    private function prosesGrup(string $kode, array $grup, array $kuitansi): array
    {
        try {
            if ($kode === '') {
                throw ValidationException::withMessages([
                    'kode_kuitansi' => 'Kode Kuitansi wajib diisi — kolom itu yang menghubungkan rincian ke sheet Kuitansi.',
                ]);
            }

            // Induknya sudah ditolak di tahap 1. Pesannya menunjuk balik ke
            // baris sheet Kuitansi supaya petugas membetulkan sheet yang benar —
            // rincian ini sendiri boleh jadi tidak ada salahnya sama sekali.
            if (isset($kuitansi['galat'][$kode])) {
                $galat = $kuitansi['galat'][$kode];

                throw ValidationException::withMessages([
                    'kode_kuitansi' => "sheet Kuitansi baris {$galat['baris']} ditolak: {$galat['pesan']}",
                ]);
            }

            if (! isset($kuitansi['siap'][$kode])) {
                throw ValidationException::withMessages([
                    'kode_kuitansi' => "Kode Kuitansi \"{$kode}\" tidak ada di sheet Kuitansi.",
                ]);
            }

            $header = $kuitansi['siap'][$kode];
            $rincian = array_map(fn ($row) => $this->rincianDariBaris($row), $grup);

            $this->periksaDuplikatDalamGrup($grup, $rincian);

            $nomor = DB::transaction(function () use ($header, $rincian) {
                $header['total'] = array_sum(array_map(
                    fn ($b) => $this->totalBaris($b),
                    $rincian
                ));

                // Ketua kelompok menahan komisinya dari uang yang ia kumpulkan:
                // satu nominal yang sama dicatat sebagai potongan (mengurangi
                // setoran) sekaligus jasa (hak ketua), dihitung dari total
                // rincian kuitansi ini. Hanya potongannya yang masuk `balance`.
                $nominalJasa = round($header['total'] * (float) $header['group_leader_fee_percent'] / 100, 2);
                $header['group_leader_deduction'] = $nominalJasa;
                $header['group_leader_fee'] = $nominalJasa;

                $this->periksaPotongan($header);

                $baru = TransactionHeader::create($header + [
                    'transaction_number' => TransactionHeader::generateNumber(),
                ]);

                foreach ($rincian as $b) {
                    $baru->transactions()->create($b);
                }

                return $baru->transaction_number;
            });

            return array_map(fn ($row) => [
                'sheet' => self::SHEET_RINCIAN,
                'baris' => $row['baris'],
                'status' => 'ok',
                'nama' => $this->labelBaris($row),
                'pesan' => $nomor,
            ], $grup);
        } catch (ValidationException $e) {
            $pesan = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return $this->gagalkanGrup($grup, $pesan);
        } catch (\Throwable $e) {
            return $this->gagalkanGrup($grup, $e->getMessage());
        }
    }

    /**
     * Tandai seluruh baris satu grup gagal.
     *
     * Baris yang tidak bersalah tetap diberi tahu alasannya menyebut baris mana
     * yang bermasalah — tanpa itu, pengguna melihat baris yang tampak benar
     * ditolak tanpa sebab dan mengira impornya rusak.
     *
     * @param  array<int, array<string, mixed>>  $grup
     * @return array<int, array<string, mixed>>
     */
    private function gagalkanGrup(array $grup, string $pesan): array
    {
        $kode = trim((string) ($grup[0]['kode_kuitansi'] ?? ''));
        $satuBaris = count($grup) === 1 || $kode === '';

        return array_map(function ($row) use ($pesan, $kode, $satuBaris) {
            return [
                'sheet' => self::SHEET_RINCIAN,
                'baris' => $row['baris'],
                'status' => 'gagal',
                'nama' => $this->labelBaris($row),
                'pesan' => $satuBaris
                    ? $pesan
                    : "Kuitansi \"{$kode}\" batal seluruhnya — {$pesan}",
            ];
        }, $grup);
    }

    /** Penanda baris di daftar hasil: nomor anggota + periodenya. */
    private function labelBaris(array $row): string
    {
        $bagian = array_filter([
            trim((string) ($row['no_anggota'] ?? '')),
            trim((string) ($row['periode'] ?? '')),
        ]);

        return implode(' · ', $bagian);
    }

    /**
     * Satu baris sheet "Kuitansi" → kolom `transaction_headers` siap simpan.
     *
     * `metode` & `jenis` dinormalkan ke huruf kecil sebelum divalidasi: petugas
     * lazim mengetik "Cash" atau "Pribadi" dengan huruf besar, dan menolak itu
     * sebagai nilai tidak sah hanya bikin bingung.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function headerDariSheet(array $row): array
    {
        $data = [];

        foreach (self::KOLOM_KUITANSI as $kolomFile => $kolomDb) {
            $nilai = $this->bersihkan($row[$kolomFile] ?? null);
            $data[$kolomDb] = ($nilai === '') ? null : $nilai;
        }

        $data['payment_method'] = mb_strtolower((string) $data['payment_method']);
        $data['transaction_type'] = mb_strtolower((string) $data['transaction_type']);

        $validator = Validator::make($data, [
            'date' => ['required', 'date'],
            'transaction_type' => ['required', Rule::in(TransactionHeader::TRANSACTION_TYPES)],
            'payment' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'payment_method' => ['required', Rule::in(TransactionHeader::PAYMENT_METHODS)],
            'member_deduction' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'group_leader_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'date.required' => 'Tanggal wajib diisi (format YYYY-MM-DD, mis. 2026-08-23).',
            'date.date' => 'Tanggal tidak dikenali. Pakai format YYYY-MM-DD, mis. 2026-08-23.',
            'transaction_type.required' => 'Jenis wajib diisi (kelompok / pribadi).',
            'transaction_type.in' => 'Jenis hanya boleh "kelompok" atau "pribadi".',
            'payment.required' => 'Dibayar wajib diisi.',
            'payment.numeric' => 'Dibayar harus berupa angka.',
            'payment_method.required' => 'Metode wajib diisi (cash / transfer / other).',
            'payment_method.in' => 'Metode hanya boleh "cash", "transfer", atau "other".',
            'numeric' => ':attribute harus berupa angka.',
            'group_leader_fee_percent.max' => 'Potongan ketua tidak boleh lebih dari 100%.',
        ], [
            'member_deduction' => 'Potongan anggota',
            'group_leader_fee_percent' => 'Potongan ketua',
        ]);

        $validator->stopOnFirstFailure();
        $data = $validator->validate();

        $data['member_deduction'] = $data['member_deduction'] ?? 0;
        $data['group_leader_fee_percent'] = $data['group_leader_fee_percent'] ?? 0;

        // Kolom Excel `potongan_anggota` selalu rupiah — tidak ada kolom satuan
        // di templatnya. Nilai ketiknya ikut diisi supaya kuitansi hasil impor
        // menampilkan potongan yang benar saat dibuka di form; tanpa ini
        // isiannya tampil 0 padahal nominalnya tidak nol.
        $data['member_deduction_type'] = 'amount';
        $data['member_deduction_input'] = $data['member_deduction'];

        // Sama dengan jalur simpan biasa: potongan & jasa ketua kelompok hanya
        // berlaku pada setoran kelompok, jadi dinolkan pada kuitansi pribadi
        // alih-alih diam-diam menggeser `balance`.
        if ($data['transaction_type'] === 'pribadi') {
            $data['group_leader_fee_percent'] = 0;
        }

        return $data;
    }

    /**
     * Satu baris file → satu rincian siap simpan.
     *
     * Anggota & tarif dirujuk lewat kode yang dilihat petugas di aplikasi
     * (`No. Anggota`, `Kode Tarif`), bukan id database — id tidak pernah tampil
     * di layar mana pun, jadi tidak ada cara wajar mengisinya dari file.
     *
     * @return array<string, mixed>
     */
    private function rincianDariBaris(array $row): array
    {
        $noAnggota = (string) $this->bersihkan($row['no_anggota'] ?? null);
        $kodeTarif = (string) $this->bersihkan($row['kode_tarif'] ?? null);
        $field = 'baris'.$row['baris'];

        if ($noAnggota === '') {
            throw ValidationException::withMessages([$field => "baris {$row['baris']}: No. Anggota wajib diisi."]);
        }

        if ($kodeTarif === '') {
            throw ValidationException::withMessages([$field => "baris {$row['baris']}: Kode Tarif wajib diisi."]);
        }

        $memberId = Member::where('member_number', $noAnggota)->value('id');

        if ($memberId === null) {
            throw ValidationException::withMessages([
                $field => "baris {$row['baris']}: anggota dengan No. Anggota \"{$noAnggota}\" tidak ada di master.",
            ]);
        }

        $rate = Rate::where('code', $kodeTarif)->first(['id', 'price', 'fee_type']);

        if ($rate === null) {
            throw ValidationException::withMessages([
                $field => "baris {$row['baris']}: tarif dengan kode \"{$kodeTarif}\" tidak ada di master.",
            ]);
        }

        $nominal = $this->bersihkan($row['nominal'] ?? null);

        // Nominal kosong diisi harga tarifnya. Iuran rutin nominalnya selalu
        // sama dengan masternya, jadi mewajibkan kolom ini hanya memaksa
        // petugas menyalin angka yang sama ratusan kali — dan tiap salinan
        // adalah kesempatan salah ketik.
        if ($nominal === null || $nominal === '') {
            $nominal = $rate->price;
        }

        $diskon = $this->bersihkan($row['diskon'] ?? null);
        $diskon = ($diskon === null || $diskon === '') ? 0 : $diskon;

        $validator = Validator::make(
            ['amount' => $nominal, 'discount' => $diskon],
            [
                'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
                'discount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            ],
            [
                'amount.numeric' => "baris {$row['baris']}: Nominal harus berupa angka.",
                'discount.numeric' => "baris {$row['baris']}: Diskon harus berupa angka.",
                'min' => "baris {$row['baris']}: :attribute tidak boleh negatif.",
            ],
            ['amount' => 'Nominal', 'discount' => 'Diskon']
        );

        $validator->stopOnFirstFailure();
        $angka = $validator->validate();

        $periode = $this->bersihkan($row['periode'] ?? null);
        $periode = ($periode === null || $periode === '') ? null : (string) $periode;

        $data = [
            'member_id' => (int) $memberId,
            'amount' => $angka['amount'],
            'discount' => $angka['discount'],
            'rate_id' => (int) $rate->id,
        ] + $this->periodeUntukTarif($periode, (int) $rate->id, $field);

        $this->periksaDiskon($data, $field);

        // Bentrok dengan data yang SUDAH tersimpan. Impor massal sengaja
        // menolak, tidak menimpa — sama dengan impor master lain di aplikasi
        // ini. Baris yang ditolak bisa diunduh, diperbaiki, lalu dikirim ulang.
        $this->periksaDuplikatPeriode($data, $field);

        return $data;
    }

    /**
     * Bentrok DI DALAM satu grup — tidak akan tertangkap pemeriksaan database
     * karena barisnya belum tersimpan saat baris berikutnya diperiksa.
     *
     * @param  array<int, array<string, mixed>>  $grup
     * @param  array<int, array<string, mixed>>  $rincian
     */
    private function periksaDuplikatDalamGrup(array $grup, array $rincian): void
    {
        $terpakai = [];

        foreach ($rincian as $i => $baris) {
            // Baris tanpa periode (tarif sekali bayar) dilewati: seluruhnya akan
            // punya kunci yang sama dan saling menuduh duplikat, padahal
            // pungutan sekali bayar memang boleh berulang.
            if ($baris['month'] === null) {
                continue;
            }

            $kunci = $baris['member_id'].'-'.$baris['rate_id'].'-'.$baris['month'].'-'.$baris['year'];

            if (isset($terpakai[$kunci])) {
                throw ValidationException::withMessages([
                    'grup' => "baris {$grup[$i]['baris']} mengulang anggota, tarif, dan periode yang sama dengan baris {$terpakai[$kunci]}.",
                ]);
            }

            $terpakai[$kunci] = $grup[$i]['baris'];
        }
    }

    /**
     * Potongan gabungan tidak boleh melebihi total — hasilnya tagihan negatif.
     *
     * @param  array<string, mixed>  $header
     */
    private function periksaPotongan(array $header): void
    {
        $potongan = (float) $header['member_deduction'] + (float) $header['group_leader_deduction'];

        if ($potongan > (float) $header['total']) {
            throw ValidationException::withMessages([
                'member_deduction' => 'potongan anggota + potongan ketua melebihi total rincian kuitansi ini.',
            ]);
        }
    }

    /** Sel Excel kosong, spasi, dan angka dinormalkan jadi satu bentuk. */
    private function bersihkan(mixed $nilai): mixed
    {
        return is_string($nilai) ? trim($nilai) : $nilai;
    }
}
