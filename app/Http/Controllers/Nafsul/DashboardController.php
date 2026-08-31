<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\TransactionHeader;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard modul Nafsul — ringkasan uang masuk iuran anggota.
 *
 * SELURUH angka di sini berasal dari kolom `payment` (uang yang BENAR-BENAR
 * diterima), bukan `total` (yang seharusnya dibayar). Keduanya kerap berbeda
 * karena potongan anggota & jasa ketua kelompok, sedangkan yang ditanyakan
 * dashboard adalah pendapatan riil — bukan tagihan.
 *
 * Tanggalnya memakai `COALESCE(date, created_at)`, sama persis dengan penyaring
 * di `TransaksiHeaderController::index()`. Kalau dashboard berangkat dari dasar
 * tanggal yang lain, angkanya tidak akan pernah cocok dengan daftar transaksi
 * yang dibuka petugas untuk memeriksanya.
 *
 * Penyaringnya RENTANG TANGGAL, seragam dengan Dashboard CSSD & Perawat —
 * ketiganya memakai `date_from` / `date_to` dengan bawaan bulan berjalan,
 * sehingga petugas yang berpindah dashboard menemukan kendali yang sama.
 */
class DashboardController extends Controller
{
    /** Ekspresi tanggal transaksi — satu-satunya sumber tanggal di file ini. */
    private const TANGGAL = 'DATE(COALESCE(`date`, created_at))';

    /** Ekspresi tahun transaksi, diturunkan dari dasar tanggal yang sama. */
    private const TAHUN = 'YEAR(COALESCE(`date`, created_at))';

    /**
     * GET /api/nafsul/dashboard
     *
     * Query: `date_from`, `date_to` (Y-m-d) — bawaan bulan berjalan.
     *
     * Rentang ini menggerakkan SELURUH isi layar: grafik harian, kartu transaksi
     * valid/belum valid, komposisi cara bayar, dan angka pendapatan periode.
     *
     * Satu pengecualian yang disengaja: `monthly_income` memuat 12 bulan penuh
     * pada TAHUN `date_from`. Grafik itu memang untuk membandingkan bulan satu
     * sama lain sepanjang setahun — dipotong mengikuti rentang, ia menyusut jadi
     * satu batang tunggal yang tidak menjawab apa pun. Rentangnya tetap
     * berpengaruh: mengubah tahunnya mengubah grafik itu juga.
     */
    public function index(Request $request): JsonResponse
    {
        [$dari, $sampai] = $this->rentangTanggal($request);
        $year = $dari->year;

        return $this->success('Dashboard Nafsul berhasil diambil.', [
            'date_from' => $dari->format('Y-m-d'),
            'date_to' => $sampai->format('Y-m-d'),
            'year' => $year,
            'summary' => $this->summary($year, $dari, $sampai),
            'monthly_income' => $this->pendapatanBulanan($year),
            'daily_income' => $this->pendapatanHarian($dari, $sampai),
            'validation' => $this->validasi($dari, $sampai),
            'payment_methods' => $this->caraBayar($dari, $sampai),
        ]);
    }

    /**
     * Rentang tanggal dari query string, bawaan BULAN BERJALAN.
     *
     * Aturannya sengaja dicerminkan dari `SummarizesOrders::rentangTanggal()`
     * yang dipakai dashboard CSSD & perawat — termasuk menukar rentang terbalik
     * diam-diam alih-alih menolaknya, karena hasilnya sudah pasti yang dimaksud
     * pengguna dan galat validasi untuk hal ini cuma menghalangi.
     *
     * Tidak menumpang trait itu: trait tersebut bicara tentang `order`, sedangkan
     * modul Nafsul tidak punya hubungan apa pun dengan peminjaman instrumen.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function rentangTanggal(Request $request): array
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $now = CarbonImmutable::now();

        $dari = $request->filled('date_from')
            ? CarbonImmutable::parse($request->input('date_from'))->startOfDay()
            : $now->startOfMonth();

        $sampai = $request->filled('date_to')
            ? CarbonImmutable::parse($request->input('date_to'))->startOfDay()
            : $now->endOfMonth()->startOfDay();

        return $dari->lte($sampai) ? [$dari, $sampai] : [$sampai, $dari];
    }

    /** Kartu angka besar: periode terpilih, setahun, rata-rata bulanan, kuitansi. */
    private function summary(int $year, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $setahun = (float) $this->tahun($year)->sum('payment');

        // Pembagi rata-rata = jumlah bulan yang SUDAH punya transaksi, bukan 12.
        // Dibagi 12 pada tahun berjalan, rata-ratanya selalu terlihat anjlok
        // hanya karena bulan-bulan yang belum terjadi ikut menekan angkanya.
        $bulanTerisi = (int) $this->tahun($year)
            ->selectRaw('COUNT(DISTINCT MONTH(COALESCE(`date`, created_at))) as jml')
            ->value('jml');

        return [
            'range_income' => round((float) $this->rentang($dari, $sampai)->sum('payment'), 2),
            'range_receipts' => (int) $this->rentang($dari, $sampai)->count(),
            'year_income' => round($setahun, 2),
            'month_average' => $bulanTerisi > 0 ? round($setahun / $bulanTerisi, 2) : 0.0,
            'year_receipts' => (int) $this->tahun($year)->count(),
        ];
    }

    /**
     * Total dibayar per bulan sepanjang satu tahun — selalu 12 baris.
     *
     * Bulan tanpa transaksi tetap dikirim bernilai 0. Kalau baris kosong
     * dibuang, batang grafiknya merapat dan bulan yang sepi terlihat seolah
     * tidak pernah ada.
     */
    private function pendapatanBulanan(int $year): array
    {
        $baris = $this->tahun($year)
            ->selectRaw('MONTH(COALESCE(`date`, created_at)) as bulan')
            ->selectRaw('SUM(payment) as total, COUNT(*) as jumlah')
            ->groupBy('bulan')
            ->get()
            ->keyBy('bulan');

        return collect(range(1, 12))->map(fn ($m) => [
            'month' => $m,
            'label' => CarbonImmutable::create($year, $m, 1)->translatedFormat('M'),
            'total' => round((float) ($baris[$m]->total ?? 0), 2),
            'count' => (int) ($baris[$m]->jumlah ?? 0),
        ])->all();
    }

    /**
     * Total dibayar per hari sepanjang rentang terpilih.
     *
     * Sama seperti grafik bulanan, hari kosong tetap dikirim bernilai 0 supaya
     * sumbu waktunya jujur: jeda libur harus terlihat sebagai jeda.
     *
     * Rentang yang sangat panjang tidak dipotong di sini; grafiknya sendiri yang
     * menjarangkan label sumbu mengikuti lebar yang tersedia.
     */
    private function pendapatanHarian(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $baris = $this->rentang($dari, $sampai)
            ->selectRaw(self::TANGGAL.' as tanggal')
            ->selectRaw('SUM(payment) as total')
            ->groupBy('tanggal')
            ->pluck('total', 'tanggal');

        $hasil = [];
        for ($hari = $dari; $hari->lte($sampai); $hari = $hari->addDay()) {
            $kunci = $hari->format('Y-m-d');
            $hasil[] = [
                'date' => $kunci,
                'day' => $hari->day,
                'label' => $hari->format('j/n'),
                'total' => round((float) ($baris[$kunci] ?? 0), 2),
            ];
        }

        return $hasil;
    }

    /**
     * Kuitansi valid vs belum valid pada rentang terpilih.
     *
     * Penanda validasi adalah `validation_at` — kolom yang diisi endpoint
     * `transaksi/header/{id}/validasi` dan dikosongkan lagi oleh batal-validasi.
     */
    private function validasi(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $jmlValid = (int) $this->rentang($dari, $sampai)->whereNotNull('validation_at')->count();
        $jmlBelum = (int) $this->rentang($dari, $sampai)->whereNull('validation_at')->count();
        $total = $jmlValid + $jmlBelum;

        return [
            'valid' => $jmlValid,
            'invalid' => $jmlBelum,
            'total' => $total,
            'valid_percent' => $total > 0 ? round($jmlValid / $total * 100, 1) : 0.0,
            // Query dibangun ulang tiap kali: `count()` di atas sudah mengeksekusi
            // builder-nya, dan memakai instance yang sama untuk `sum()` akan
            // menumpuk agregat.
            'valid_amount' => round((float) $this->rentang($dari, $sampai)
                ->whereNotNull('validation_at')->sum('payment'), 2),
            'invalid_amount' => round((float) $this->rentang($dari, $sampai)
                ->whereNull('validation_at')->sum('payment'), 2),
        ];
    }

    /**
     * Komposisi cara bayar pada rentang terpilih — inilah "tunai berapa persen".
     *
     * Persentasenya dihitung dari NOMINAL, bukan dari jumlah kuitansi: satu
     * transfer besar dan satu setoran tunai kecil bukan berarti tunai 50%.
     * Jumlah kuitansi tetap dikirim (`count`) untuk ditampilkan berdampingan.
     *
     * Seluruh nilai `PAYMENT_METHODS` selalu muncul walau nol, agar urutan dan
     * warna tiap potongan tidak berpindah-pindah antar periode.
     */
    private function caraBayar(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $label = ['transfer' => 'Transfer', 'cash' => 'Tunai', 'other' => 'Lain-lain'];

        $baris = $this->rentang($dari, $sampai)
            ->selectRaw('payment_method')
            ->selectRaw('SUM(payment) as total, COUNT(*) as jumlah')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $totalSemua = (float) $baris->sum(fn ($b) => (float) $b->total);

        return collect(TransactionHeader::PAYMENT_METHODS)
            ->map(fn ($metode) => [
                'method' => $metode,
                'label' => $label[$metode] ?? $metode,
                'count' => (int) ($baris[$metode]->jumlah ?? 0),
                'total' => round((float) ($baris[$metode]->total ?? 0), 2),
                'percent' => $totalSemua > 0
                    ? round((float) ($baris[$metode]->total ?? 0) / $totalSemua * 100, 1)
                    : 0.0,
            ])
            ->all();
    }

    /**
     * Kuitansi yang boleh dihitung.
     *
     * Global scope `active` sudah membuang baris terhapus, jadi yang perlu
     * ditegaskan di sini hanya `payment` yang terisi — kuitansi tanpa pembayaran
     * bukan pendapatan.
     */
    private function scope()
    {
        return TransactionHeader::query()->whereNotNull('payment');
    }

    /** `scope()` yang dibatasi satu tahun. */
    private function tahun(int $year)
    {
        return $this->scope()->whereRaw(self::TAHUN.' = ?', [$year]);
    }

    /** `scope()` yang dibatasi rentang tanggal. */
    private function rentang(CarbonImmutable $dari, CarbonImmutable $sampai)
    {
        return $this->scope()
            ->whereRaw(self::TANGGAL.' >= ?', [$dari->format('Y-m-d')])
            ->whereRaw(self::TANGGAL.' <= ?', [$sampai->format('Y-m-d')]);
    }
}
