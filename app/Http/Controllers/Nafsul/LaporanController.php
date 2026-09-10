<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Laporan modul Nafsul — lembar cetak bulanan Binroh, satu-satunya bentuk
 * laporan modul ini.
 *
 * `rekapPembayaran()` mengembalikan sebulan pembayaran yang dipecah per CARA
 * BAYAR, tiap blok ditutup total kotor, total potongan, dan total bersih. Satu
 * baris = satu ANGGOTA pada satu KUITANSI, bukan satu periode iuran.
 *
 * BUKAN pengulangan daftar transaksi yang sudah ada. Daftar itu alat kerja
 * harian — dipagi 25 baris, tanpa penjumlahan. Lembar ini justru tidak
 * dipaginasi sama sekali, karena diakhiri baris total yang harus mencakup
 * seluruh isinya; total yang berdiri di bawah SEBAGIAN baris adalah angka yang
 * salah tanpa terlihat salah.
 *
 * Tidak ada endpoint export terpisah: frontend merangkai .xlsx di peramban dari
 * respons yang sama dengan yang mengisi layar — lihat `lib/excel.ts`. Satu
 * sumber angka untuk layar dan berkas.
 *
 * Seperti controller Nafsul lain, responsnya JSON polos (bukan pembungkus
 * `success`/`error`) karena `lib/nafsul/api.ts` membaca body-nya langsung.
 */
class LaporanController extends Controller
{
    use HandlesTransactionRows;

    /**
     * Pagar terakhir jumlah baris `rekapPembayaran()`, yang memang tidak
     * dipaginasi.
     *
     * Bukan ukuran halaman: sebulan setoran berorde ratusan baris, jauh di
     * bawah angka ini. Yang dijaga adalah keadaan tidak wajar — impor yang
     * salah tanggal sehingga bertahun-tahun setoran menumpuk di satu bulan —
     * agar tidak menarik seluruh tabel ke memori PHP dan mematikan prosesnya.
     */
    private const MAX_ROWS = 20000;

    /** Baris per halaman bawaan lembar rekap. */
    private const PER_HALAMAN = 10;

    /**
     * Cara bayar yang dicetak di lembar ini, dalam urutan bloknya.
     *
     * Urutannya DIPATOK di sini, bukan mengikuti apa yang kebetulan ada
     * datanya: bulan tanpa setoran transfer tidak boleh membuat tunai naik ke
     * posisi blok pertama.
     *
     * Lembar Binroh sendiri cuma punya dua blok, TRANSFER dan TUNAI. Blok
     * ketiga, LAIN-LAIN, ada karena setoran 2014–2024 dari sistem lama masuk
     * tanpa penanda cara bayar sama sekali — 4.720 kuitansi, lebih dari 90%
     * isi basis data, seluruhnya `other`. Tanpa blok itu, dua belas tahun
     * setoran hilang dari laporan; dan menyerapnya ke blok TUNAI hanya
     * membuat laporan menyebut sesuatu yang tidak diketahui siapa pun.
     * Begitu cara bayarnya dibereskan, blok ini kosong dengan sendirinya dan
     * tidak lagi tercetak.
     */
    private const METODE_LEMBAR = ['transfer', 'cash', 'other'];

    /**
     * GET /api/nafsul/laporan/rekap-pembayaran
     *
     * Rekap SATU BULAN pembayaran, dipecah per cara bayar — bentuk cetak yang
     * dipakai Binroh untuk laporan bulanan ("PEMBAYARAN TRANSFER/TUNAI - NAFSUL
     * APRIL 2026"), lengkap dengan total kotor, total potongan, dan total
     * bersih tiap bloknya.
     *
     * BUKAN pengulangan `perAnggota()`. Laporan itu memecah per PERIODE iuran —
     * seorang anggota yang melunasi 12 bulan sekaligus muncul 12 baris. Rekap
     * ini memecah per ANGGOTA PER KUITANSI: orang yang sama pada kuitansi yang
     * sama selalu satu baris, berapa pun periode yang dilunasinya, karena yang
     * ditanyakan lembar ini adalah "siapa menyetor berapa", bukan "iuran bulan
     * apa saja yang tertutup".
     *
     * Query: `period` (MM/YYYY, opsional), `date` (YYYY-MM-DD, opsional),
     * `search` (opsional), `payment_method` (opsional).
     *
     * `date` mempersempit ke SATU HARI di dalam bulan itu — dipakai saat
     * petugas menelusuri setoran satu tanggal tertentu. Bulannya tetap
     * diturunkan dari tanggal itu, bukan dari `period`, supaya judul dan baris
     * total tidak pernah menyebut bulan yang berbeda dari isinya.
     *
     * `period` yang KOSONG berarti BULAN BERJALAN, sekalipun bulan itu belum
     * ada setorannya sama sekali. Halaman yang membuka bulan lain akan
     * mengejutkan: petugas membuka laporan untuk melihat bulan yang sedang
     * berjalan, dan bulan yang tampil harus sama dengan bulan di kalender —
     * lembar kosong pada awal bulan adalah jawaban yang benar, dan pesan "tidak
     * ada pembayaran yang tercatat pada bulan ini" sudah menyebutkannya.
     *
     * Penyaringnya dikerjakan DI SINI, bukan di peramban atas baris yang sudah
     * terkirim: tiap blok ditutup baris total, dan total yang dihitung ulang di
     * frontend akan jadi versi kedua dari angka yang sama — dua tempat yang
     * bisa berselisih tanpa ada yang tahu mana yang benar. Dengan menyaring di
     * query, `summary` selalu milik baris yang benar-benar tercetak.
     *
     * Tidak dipaginasi, dan itu disengaja: lembarannya dibaca sebagai satu
     * kesatuan yang diakhiri baris total, dan total yang berdiri di bawah
     * SEBAGIAN baris adalah angka yang salah tanpa terlihat salah. Sebulan
     * setoran memang berhingga — orde ratusan baris. `MAX_ROWS` hanya pagar
     * terakhir supaya data yang tidak wajar tidak sampai mematikan proses PHP,
     * dan bila terpakai, `truncated` menyebutkannya alih-alih diam-diam
     * memotong.
     */
    public function rekapPembayaran(Request $request)
    {
        $request->validate([
            'period' => ['nullable', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            // Rentang bebas, boleh memotong beberapa bulan. Menang atas `period`
            // maupun `date` bila diisi lengkap.
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            // Dicocokkan ke nama anggota, no. anggota, no. kuitansi, dan nama
            // ketua — empat kolom yang dibaca petugas saat menelusuri lembar.
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(self::METODE_LEMBAR)],
            'page' => ['nullable', 'integer', 'min:1'],
            // Batas atas 5000 supaya export bisa menarik seluruh lembar dalam
            // satu permintaan tanpa membuka pintu untuk permintaan tanpa batas.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            // `detail=1` mengembalikan bentuk LAMA: satu baris per anggota per
            // kuitansi, datar. Dipakai export .xlsx, yang lembarnya memang
            // merentangkan seluruh anggota — baris yang di layar tersembunyi di
            // balik lipatan tidak akan pernah terbuka di dalam Excel.
            'detail' => ['nullable', 'boolean'],
        ]);

        $diminta = trim((string) $request->query('period', ''));
        $tanggal = trim((string) $request->query('date', ''));
        $dari = trim((string) $request->query('date_from', ''));
        $sampai = trim((string) $request->query('date_to', ''));
        $pakaiRentang = $dari !== '' && $sampai !== '';

        $periode = match (true) {
            // Rentang menang atas semuanya. `month`/`year` pada respons diisi
            // dari tanggal AWAL rentang — nilai itu cuma penanda, sedangkan yang
            // menentukan isi lembar adalah `date_from`/`date_to` yang ikut
            // dikembalikan.
            $pakaiRentang => ['month' => (int) date('n', strtotime($dari)), 'year' => (int) date('Y', strtotime($dari))],
            // Tanggal menang atas `period`: keduanya bisa saja tidak sejalan
            // (isian bulan tertinggal saat tanggalnya diganti), dan yang benar
            // selalu bulan tempat tanggal itu berada.
            $tanggal !== '' => ['month' => (int) date('n', strtotime($tanggal)), 'year' => (int) date('Y', strtotime($tanggal))],
            $diminta === '' => ['month' => (int) now()->month, 'year' => (int) now()->year],
            default => $this->pecahPeriode($diminta, 'period'),
        };

        // Rentang dirakit di PHP, bukan lewat WHERE MONTH()/YEAR(): fungsi atas
        // kolom membuat index `transaction_headers.date` tidak terpakai.
        $awal = sprintf('%04d-%02d-01', $periode['year'], $periode['month']);
        $akhir = date('Y-m-t', strtotime($awal));

        // Satu hari = rentang sehari. Ditulis sebagai penyempitan rentang, bukan
        // sebagai syarat terpisah, supaya seluruh sisa fungsi ini tetap bekerja
        // atas satu pengertian rentang saja.
        if ($tanggal !== '') {
            $awal = $akhir = $tanggal;
        }

        // Rentang bebas: ditulis paling akhir agar menimpa dua bentuk di atas.
        if ($pakaiRentang) {
            $awal = $dari;
            $akhir = $sampai;
        }

        $cari = trim((string) $request->query('search', ''));
        $metode = (string) $request->query('payment_method', '');
        $rinci = $request->boolean('detail');

        $perHalaman = (int) $request->integer('per_page', self::PER_HALAMAN);
        $halaman = max((int) $request->integer('page', 1), 1);

        // Layar meminta KUITANSI saja — langsung dari `transaction_headers`,
        // anggotanya menyusul lewat `anggotaKuitansi()` saat barisnya dibuka.
        // Export (`detail=1`) meminta seluruh baris anggota sekaligus. Keduanya
        // memakai rentang, cara bayar, dan kata kunci dengan pengertian yang
        // sama, jadi tidak ada kuitansi yang tampil di satu bentuk saja.
        [$blok, $total] = $rinci
            ? $this->lembarAnggota($awal, $akhir, $cari, $metode, $halaman, $perHalaman)
            : $this->lembarKuitansi($awal, $akhir, $cari, $metode, $halaman, $perHalaman);

        $halamanTerakhir = max((int) ceil($total / max($perHalaman, 1)), 1);

        return response()->json([
            'period' => [
                'month' => $periode['month'],
                'year' => $periode['year'],
                'date_from' => $awal,
                'date_to' => $akhir,
                // Dikembalikan apa adanya supaya frontend bisa membedakan
                // "sebulan penuh" dari "satu tanggal" tanpa menebak dari
                // rentangnya.
                'date' => $tanggal !== '' ? $tanggal : null,
            ],
            'blocks' => $blok,
            'pagination' => [
                'page' => $halaman,
                'per_page' => $perHalaman,
                'total' => $total,
                'last_page' => $halamanTerakhir,
            ],
            // Dipertahankan demi bentuk respons yang tetap; dengan paginasi
            // muatannya sudah dibatasi per halaman sehingga tidak pernah lagi
            // ada yang terpotong diam-diam.
            'truncated' => false,
        ]);
    }

    /**
     * Lembar LAYAR: satu baris per KUITANSI, diambil langsung dari
     * `transaction_headers`.
     *
     * Dulu daftar ini dirakit dari query tingkat anggota yang di-GROUP BY dua
     * kali (anggota per kuitansi → kuitansi), ditambah query ringkasan atas
     * subquery yang sama: seluruh rincian dalam rentang itu di-join dan
     * dijumlahkan hanya untuk menampilkan 50 nomor kuitansi. Sekarang header
     * lebih dulu:
     *
     *  1. hitung kuitansi per cara bayar (untuk paginasi) — header saja;
     *  2. ambil satu halaman header;
     *  3. lengkapi nama ketua & jumlah anggota HANYA untuk header di halaman itu.
     *
     * Nominal tidak dihitung: layar tidak menampilkannya, dan angka per anggota
     * datang lewat `anggotaKuitansi()` saat barisnya dibuka.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: int}
     */
    private function lembarKuitansi(
        string $awal,
        string $akhir,
        string $cari,
        string $metode,
        int $halaman,
        int $perHalaman,
    ): array {
        $dasar = $this->queryHeaderKuitansi($awal, $akhir, $cari, $metode);

        $jumlah = (clone $dasar)
            ->selectRaw('h.payment_method, COUNT(*) as kuitansi')
            ->groupBy('h.payment_method')
            ->pluck('kuitansi', 'payment_method');

        $header = (clone $dasar)
            ->select(['h.id', 'h.uuid', 'h.date', 'h.transaction_number', 'h.transaction_type', 'h.payment_method'])
            // Kronologis per hari, lalu urutan input — sama dengan lembar export.
            ->orderBy('h.date')
            ->orderBy('h.id')
            ->forPage($halaman, $perHalaman)
            ->get();

        $pelengkap = $header->isEmpty()
            ? collect()
            : DB::table('transactions as t')
                ->join('members as m', 'm.id', '=', 't.member_id')
                ->leftJoin('group_leaders as gl', function ($join) {
                    $join->on('gl.id', '=', 'm.group_leader_id')
                        ->whereNull('gl.deleted_by');
                })
                ->whereIn('t.transaction_header_id', $header->pluck('id'))
                ->whereNull('t.deleted_by')
                ->whereNull('m.deleted_by')
                ->selectRaw(implode(', ', [
                    't.transaction_header_id',
                    'MAX(gl.name) as group_leader_name',
                    'COUNT(DISTINCT m.id) as members_count',
                ]))
                ->groupBy('t.transaction_header_id')
                ->get()
                ->keyBy('transaction_header_id');

        $perMetode = $header->groupBy('payment_method');

        $blok = collect(self::METODE_LEMBAR)
            ->map(fn (string $m) => [
                'payment_method' => $m,
                'rows' => $perMetode->get($m, collect())
                    ->map(fn ($h) => $this->barisKuitansi($h, $pelengkap->get($h->id)))
                    ->values()
                    ->all(),
            ])
            // Cara bayar yang tidak muncul di HALAMAN ini tidak dicetak sebagai
            // blok kosong — itu terbaca sebagai laporan yang gagal.
            ->filter(fn (array $b) => $b['rows'] !== [])
            ->values();

        return [$blok, (int) $jumlah->sum()];
    }

    /**
     * Lembar EXPORT (`detail=1`): satu baris per ANGGOTA per kuitansi, lengkap
     * dengan angka penutup tiap cara bayar.
     *
     * Jumlah & total dihitung atas SELURUH hasil penyaringan, bukan atas
     * halaman yang diminta — baris penutup menyebut total lembar ini.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: int}
     */
    private function lembarAnggota(
        string $awal,
        string $akhir,
        string $cari,
        string $metode,
        int $halaman,
        int $perHalaman,
    ): array {
        $dasar = $this->queryRekapPembayaran($awal, $akhir, $cari, $metode);

        $ringkas = DB::query()->fromSub($dasar, 'r')
            ->selectRaw('payment_method, COUNT(*) as jml, SUM(amount) as amount, SUM(deduction) as deduction')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $baris = (clone $dasar)->forPage($halaman, $perHalaman)->get();

        $this->tandaiKunjungan($baris);

        $perMetode = $baris->groupBy('payment_method');

        $blok = collect(self::METODE_LEMBAR)
            ->map(fn (string $m) => $this->blokRekap($m, $perMetode->get($m, collect()), $ringkas->get($m)))
            ->filter(fn (array $b) => $b['rows'] !== [])
            ->values();

        return [$blok, (int) $ringkas->sum('jml')];
    }

    /**
     * Satu blok cara bayar lembar export: barisnya apa adanya, plus tiga angka
     * penutup dari ringkasan SELURUH hasil penyaringan.
     *
     * `net` dihitung dari selisih dua angka di atasnya, bukan dijumlah sendiri
     * dari baris — bentuk ini tidak bisa melenceng dari keduanya.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $baris
     */
    private function blokRekap(string $metode, $baris, ?object $ringkas): array
    {
        $bruto = (float) ($ringkas->amount ?? 0);
        $potongan = (float) ($ringkas->deduction ?? 0);

        return [
            'payment_method' => $metode,
            'rows' => $baris->map(fn ($r) => $this->barisAnggota($r))->values()->all(),
            'summary' => [
                'rows' => (int) ($ringkas->jml ?? 0),
                'amount' => $this->rupiah($bruto),
                'deduction' => $this->rupiah($potongan),
                'net' => $this->rupiah($bruto - $potongan),
            ],
        ];
    }

    /**
     * Satu baris tingkat ANGGOTA — bentuk yang dipakai export dan endpoint
     * rincian, keduanya lewat perakit yang sama supaya lembar .xlsx dan daftar
     * yang terbuka di layar tidak pernah menyebut angka yang berbeda.
     */
    private function barisAnggota(object $r): array
    {
        return [
            // Kunci baris untuk React. Pasangan kuitansi+anggota memang sudah
            // unik di sini — itu tepat yang di-GROUP BY.
            'key' => $r->header_id.'-'.$r->member_id,
            'date' => $r->date,
            'transaction_number' => $r->transaction_number,
            'transaction_type' => $r->transaction_type,
            // Diabaikan frontend pada kuitansi pribadi — yang tampil "Pribadi".
            // Tetap dikirim agar bentuk barisnya satu macam.
            'group_leader_name' => $r->group_leader_name,
            'member_name' => $r->member_name,
            'member_number' => $r->member_number,
            // "B" = kuitansi ini memuat iuran PALING AWAL anggota itu, "L" = ia
            // sudah punya iuran yang lebih dulu. Diturunkan di tandaiKunjungan(),
            // bukan dibaca dari kolom. Null bila tidak dihitung.
            'visit' => $r->visit ?? null,
            'amount' => $this->rupiah($r->amount),
            'deduction' => $this->rupiah($r->deduction),
        ];
    }

    /**
     * Satu baris tingkat KUITANSI — isi layar rekap.
     *
     * Hanya memuat yang ada di `transaction_headers`: tanggal, nomor, jenis,
     * cara bayar, plus nama ketua kelompok yang menyertainya. Nominal TIDAK ikut
     * — angka per anggota adalah milik `transactions`, dan menaruh jumlah
     * sekuitansi di kolom yang sama dengan jumlah seorang anggota membuat satu
     * kolom memuat dua satuan yang tidak bisa dibedakan.
     *
     * `uuid` dipakai frontend untuk meminta rinciannya saat barisnya dibuka.
     *
     * `$pelengkap` = nama ketua & jumlah anggota dari rincian kuitansi itu;
     * null bila tidak ada (secara query tidak terjadi — lihat whereExists di
     * `queryHeaderKuitansi()`).
     */
    private function barisKuitansi(object $h, ?object $pelengkap): array
    {
        return [
            'key' => $h->uuid,
            'uuid' => $h->uuid,
            'date' => $h->date,
            'transaction_number' => $h->transaction_number,
            'transaction_type' => $h->transaction_type,
            'group_leader_name' => $pelengkap?->group_leader_name,
            'members_count' => (int) ($pelengkap?->members_count ?? 0),
        ];
    }

    /**
     * GET /api/nafsul/laporan/rekap-pembayaran/{transaksiHeader}/anggota
     *
     * Anggota pada SATU kuitansi — rincian yang diminta layar rekap saat baris
     * kuitansinya dibuka.
     *
     * Diambil terpisah, bukan ikut terkirim bersama daftar kuitansinya: sebuah
     * halaman berisi 50 kuitansi bisa memuat ribuan baris anggota, dan hampir
     * semuanya tidak pernah dibuka. Yang dikirim di muka cuma yang benar-benar
     * tampil.
     *
     * Memakai query yang SAMA dengan lembar rekapnya, hanya dipersempit ke satu
     * kuitansi — tanpa penyaring tanggal, kata kunci, maupun cara bayar: baris
     * yang dibuka harus memuat SELURUH anggota kuitansi itu. Menyaringnya ulang
     * dengan kata kunci akan menyembunyikan anggota lain pada kuitansi yang
     * sama, dan jumlah yang terbaca di layar tidak lagi jumlah kuitansi itu.
     */
    public function anggotaKuitansi(TransactionHeader $transaksiHeader)
    {
        $baris = $this->queryRekapPembayaran(null, null, '', '', $transaksiHeader->id)->get();

        $this->tandaiKunjungan($baris);

        return response()->json([
            'rows' => $baris->map(fn ($r) => $this->barisAnggota($r))->values()->all(),
        ]);
    }

    /**
     * KUITANSI dalam rentang — query atas `transaction_headers` saja, tanpa
     * GROUP BY dan tanpa menjumlahkan rincian.
     *
     * Himpunannya SAMA dengan kuitansi yang muncul di `queryRekapPembayaran()`:
     * header yang tidak dihapus, cara bayarnya tercetak, dan punya minimal satu
     * rincian aktif milik anggota aktif. Syarat terakhir ditulis sebagai
     * `whereExists` (semi-join lewat index `transaction_header_id`) — berhenti
     * di rincian pertama yang cocok, alih-alih menggabungkan seluruhnya.
     *
     * Kata kunci ikut di dalam `whereExists` yang sama: kuitansi cocok bila
     * nomornya cocok, atau salah satu anggota/ketuanya cocok — persis
     * pengertian pencarian di lembar export.
     */
    private function queryHeaderKuitansi(string $awal, string $akhir, string $cari, string $metode)
    {
        return DB::table('transaction_headers as h')
            ->whereNull('h.deleted_by')
            ->whereBetween('h.date', [$awal, $akhir])
            ->whereIn('h.payment_method', self::METODE_LEMBAR)
            ->when($metode !== '', fn ($q) => $q->where('h.payment_method', $metode))
            ->whereExists(function ($q) use ($cari) {
                $q->selectRaw('1')
                    ->from('transactions as t')
                    ->join('members as m', 'm.id', '=', 't.member_id')
                    ->whereColumn('t.transaction_header_id', 'h.id')
                    ->whereNull('t.deleted_by')
                    ->whereNull('m.deleted_by')
                    ->when($cari !== '', function ($q) use ($cari) {
                        $suku = $this->sukuCari($cari);

                        $q->leftJoin('group_leaders as gl', function ($join) {
                            $join->on('gl.id', '=', 'm.group_leader_id')
                                ->whereNull('gl.deleted_by');
                        })->where(function ($q) use ($suku) {
                            $q->where('h.transaction_number', 'like', $suku)
                                ->orWhere('m.name', 'like', $suku)
                                ->orWhere('m.member_number', 'like', $suku)
                                ->orWhere('gl.name', 'like', $suku);
                        });
                    });
            });
    }

    /**
     * Pola LIKE dari kata kunci. `%` dan `_` diperlakukan sebagai huruf biasa:
     * petugas mengetik nomor kuitansi, bukan pola LIKE.
     */
    private function sukuCari(string $cari): string
    {
        return '%'.addcslashes($cari, '%_\\').'%';
    }

    /**
     * Satu baris per ANGGOTA per KUITANSI dalam rentang tanggal kuitansi.
     *
     * Berangkat dari `transactions` lalu digabungkan, bukan dari
     * `transaction_headers`: nominal yang dicetak adalah nominal per anggota,
     * dan angka itu hanya ada di rincian — kolom `total`/`member_deduction` di
     * header sudah terlanjur menjumlah seluruh anggota dalam kuitansi itu.
     *
     * Join ke header bersifat INNER: rincian yang belum dibayar tidak punya
     * tanggal, nomor kuitansi, maupun cara bayar — tiga kolom yang justru
     * menyusun lembar ini. Tagihan yang belum masuk memang bukan isi lembar
     * pembayaran, dan ditelusuri lewat daftar transaksi.
     *
     * `$cari` dan `$metode` boleh kosong, dan kosong berarti "tanpa penyaring"
     * — bukan "cocokkan dengan string kosong". Begitu pula `$awal`/`$akhir` yang
     * null: dipakai `anggotaKuitansi()`, yang menunjuk satu kuitansi lewat
     * `$headerId` dan justru TIDAK boleh menyaring apa pun lagi di atasnya.
     */
    private function queryRekapPembayaran(
        ?string $awal,
        ?string $akhir,
        string $cari = '',
        string $metode = '',
        ?int $headerId = null,
    ) {
        return Transaction::query()
            ->withoutGlobalScope('active')
            ->join('transaction_headers as h', function ($join) {
                $join->on('h.id', '=', 'transactions.transaction_header_id')
                    ->whereNull('h.deleted_by');
            })
            ->join('members as m', 'm.id', '=', 'transactions.member_id')
            // LEFT join: anggota boleh belum punya ketua kelompok, dan barisnya
            // tetap harus tercetak — pada kuitansi pribadi kolom itu memang
            // tidak dipakai sama sekali.
            ->leftJoin('group_leaders as gl', function ($join) {
                $join->on('gl.id', '=', 'm.group_leader_id')
                    ->whereNull('gl.deleted_by');
            })
            ->whereNull('transactions.deleted_by')
            ->whereNull('m.deleted_by')
            // Kolom `date` dibandingkan apa adanya (bukan DATE(COALESCE(...)))
            // supaya index-nya terpakai. Aman: `date` wajib di API & impor, dan
            // baris lama sudah diisi dari `created_at` lewat migrasi.
            ->when($awal !== null, fn ($q) => $q->where('h.date', '>=', $awal))
            ->when($akhir !== null, fn ($q) => $q->where('h.date', '<=', $akhir))
            ->when($headerId !== null, fn ($q) => $q->where('h.id', $headerId))
            // Disaring di QUERY, bukan dibuang saat merakit blok: kalau baris
            // cara bayar lain ikut terambil, ia ikut memakan `MAX_ROWS` dan bisa
            // membuat lembar terpotong oleh baris yang tidak pernah tercetak.
            ->whereIn('h.payment_method', self::METODE_LEMBAR)
            ->when($metode !== '', fn ($q) => $q->where('h.payment_method', $metode))
            // Dibungkus closure supaya seluruh OR-nya berdiri sebagai satu
            // syarat; tanpa kurung itu, `orWhere` pertama membatalkan penyaring
            // tanggal di atasnya dan lembarnya berisi bulan-bulan lain.
            ->when($cari !== '', function ($q) use ($cari) {
                $suku = $this->sukuCari($cari);

                $q->where(function ($q) use ($suku) {
                    $q->where('m.name', 'like', $suku)
                        ->orWhere('m.member_number', 'like', $suku)
                        ->orWhere('h.transaction_number', 'like', $suku)
                        ->orWhere('gl.name', 'like', $suku);
                });
            })
            ->selectRaw(implode(', ', [
                'h.id as header_id',
                // Kunci publik kuitansi; route model binding memakai uuid.
                'h.uuid',
                'DATE(COALESCE(h.`date`, h.created_at)) as `date`',
                'h.transaction_number',
                'h.transaction_type',
                'h.payment_method',
                'm.id as member_id',
                'm.name as member_name',
                'm.member_number',
                // Bahan penentu kolom Kunjungan (B/L). `m.visit` TIDAK dipakai:
                // kolom itu null pada seluruh baris, jadi lembarnya selalu
                // kosong di kolom ini. Lihat tandaiKunjungan().
                'MIN(CASE WHEN transactions.month IS NOT NULL'
                    .' THEN transactions.year * 100 + transactions.month END) as periode_awal',
                'COUNT(transactions.id) as rincian_dalam',
                'gl.name as group_leader_name',
                'COALESCE(SUM(transactions.amount), 0) as amount',
                'COALESCE(SUM(transactions.discount), 0) as deduction',
            ]))
            // Seluruh kolom non-agregat ikut di GROUP BY, bukan hanya pasangan
            // kuncinya: dengan `ONLY_FULL_GROUP_BY` menyala (bawaan MySQL 5.7+)
            // query yang menyebut kolom di luar daftar ini langsung ditolak.
            ->groupByRaw(implode(', ', [
                'h.id', 'h.uuid', 'h.`date`', 'h.created_at', 'h.transaction_number',
                'h.transaction_type', 'h.payment_method',
                'm.id', 'm.name', 'm.member_number', 'gl.name',
            ]))
            // Urut seperti lembar aslinya dibaca: kronologis per hari, lalu per
            // kuitansi dalam URUTAN INPUT (`h.id`), bukan urutan nomor kuitansi
            // — nomor dibangkitkan per hari dan tidak selalu naik searah dengan
            // urutan penerimaannya. `m.id` sebagai pemecah seri agar dua anggota
            // bernama sama tidak bertukar posisi antar permintaan.
            ->orderByRaw('DATE(COALESCE(h.`date`, h.created_at))')
            ->orderBy('h.id')
            ->orderBy('m.name')
            ->orderBy('m.id');
    }

    /**
     * Isi kolom Kunjungan tiap baris: "B" bila kuitansi itulah yang memuat iuran
     * PALING AWAL si anggota, "L" bila ia sudah punya iuran yang lebih dulu.
     *
     * Aturannya sengaja sama persis dengan biling (lihat
     * TransaksiHeaderController::barisBilingPerAnggota) — satu anggota tidak
     * boleh terbaca "B" di lembar rekap tapi "L" di bilingnya sendiri.
     *
     * Dasarnya periode TERAWAL, bukan sekadar "punya transaksi lain": kalau
     * memakai yang kedua, memasukkan iuran bulan berikutnya akan mengubah lembar
     * yang sudah tercetak dari B jadi L.
     *
     * Dihitung di PHP lewat dua query ringkasan, bukan sebagai subquery per
     * baris: subquery berkorelasi akan dijalankan sekali untuk tiap baris lembar
     * — pada lembar sepanjang MAX_ROWS itu ribuan kali.
     */
    private function tandaiKunjungan($baris): void
    {
        $idAnggota = $baris->pluck('member_id')->unique()->values()->all();

        if ($idAnggota === []) {
            return;
        }

        $awalGlobal = Transaction::whereIn('member_id', $idAnggota)
            ->whereNotNull('month')
            ->selectRaw('member_id, MIN(year * 100 + month) AS awal')
            ->groupBy('member_id')
            ->pluck('awal', 'member_id');

        // Cadangan untuk anggota yang seluruh iurannya tarif SEKALI BAYAR:
        // mereka tidak punya periode sama sekali, jadi tidak ada yang bisa
        // dibandingkan — yang tersisa cuma "punya iuran di luar kuitansi ini".
        $totalRincian = Transaction::whereIn('member_id', $idAnggota)
            ->selectRaw('member_id, COUNT(*) AS jml')
            ->groupBy('member_id')
            ->pluck('jml', 'member_id');

        foreach ($baris as $r) {
            $awalBaris = $r->periode_awal === null ? null : (int) $r->periode_awal;

            if ($awalBaris === null) {
                $diLuar = (int) ($totalRincian[$r->member_id] ?? 0) - (int) $r->rincian_dalam;
                $baru = $diLuar <= 0;
            } else {
                $baru = (int) ($awalGlobal[$r->member_id] ?? PHP_INT_MAX) >= $awalBaris;
            }

            $r->visit = $baru ? 'B' : 'L';
        }
    }

    /**
     * Angka rupiah sebagai string dua desimal — bentuk yang sama dengan kolom
     * DECIMAL yang keluar dari Eloquent.
     *
     * Dijadikan string, bukan float, supaya frontend tidak perlu membedakan
     * angka dari baris (string) dan angka dari rekap (float) saat memformatnya.
     */
    private function rupiah(mixed $nilai): string
    {
        return number_format((float) $nilai, 2, '.', '');
    }
}
