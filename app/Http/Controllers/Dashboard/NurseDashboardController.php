<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Dashboard\NurseLoanFigures;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard perawat — sudut pandang PEMINJAM alat.
 *
 * Jauh lebih ringkas dari Dashboard CSSD dan memang disengaja: perawat cuma
 * perlu tahu berapa banyak alat yang dipinjam ruangannya bulan ini, berapa yang
 * masih dipegang, dan berapa yang belum dikembalikan. Isi gudang steril dan
 * peringkat ruangan adalah urusan petugas CSSD.
 *
 * API-nya BERDIRI SENDIRI: seluruh angkanya datang dari
 * [NurseLoanFigures] yang tidak dipakai layar lain, bukan dari trait
 * `SummarizesOrders` yang dibagi dengan Dashboard CSSD. Sebelumnya keduanya
 * berbagi satu perhitungan, sehingga memperbaiki "belum dikembalikan" di layar
 * ini otomatis menggeser angka di layar sebelah tanpa ada yang memintanya.
 */
class NurseDashboardController extends Controller
{
    /**
     * Banyaknya ruangan yang tampil sebagai seri warna sendiri pada grafik. Sisanya
     * dilipat jadi satu seri "Lainnya".
     *
     * Empat, bukan lebih: itu batas jumlah warna kategori yang masih bisa dibedakan
     * satu sama lain — termasuk oleh pembaca dengan buta warna — saat dipakai sebagai
     * potongan batang bertumpuk yang bisa bersebelahan dalam kombinasi apa pun.
     */
    private const MAX_SERI_RUANGAN = 4;

    /** Kunci seri penampung ruangan di luar peringkat atas. */
    private const KUNCI_LAINNYA = 'lainnya';

    public function __construct(private readonly NurseLoanFigures $angka) {}

    /**
     * GET /api/nurse/dashboard
     *
     * Query: `date_from`, `date_to` (Y-m-d) — bawaan bulan berjalan, hanya
     * membatasi grafik dan angka "total pinjam" pada periode itu.
     *
     * Kartu "sedang dipinjam" dan "belum dikembalikan" SELALU keadaan saat ini:
     * alat yang dipinjam bulan lalu dan belum kembali justru yang paling perlu
     * terlihat, dan menyaringnya per bulan akan menyembunyikannya.
     *
     * Semua kartu & grafik satu layar datang dalam SATU respons supaya angkanya
     * dipotret pada detik yang sama. Kalau tiap kartu memanggil endpointnya
     * sendiri, order yang didistribusikan di sela-sela permintaan membuat "sedang
     * dipinjam" dan "belum dikembalikan" di layar yang sama saling bertentangan.
     */
    public function index(Request $request): JsonResponse
    {
        [$dari, $sampai] = $this->rentangTanggal($request);

        $belum = $this->angka->belumDikembalikan();

        return $this->success('Dashboard perawat berhasil diambil.', [
            'date_from' => $dari->format('Y-m-d'),
            'date_to' => $sampai->format('Y-m-d'),
            'summary' => [
                'period_orders' => (int) $this->orderRentang($dari, $sampai)->count(),
                'period_items' => $this->unitPeriode($dari, $sampai),
                'currently_borrowed' => $this->angka->sedangDipinjam(),
                // Angka kartu = set paket + unit satuan. Rinciannya ikut dikirim
                // karena "8" saja tidak memberi tahu apakah itu 8 bungkus paket
                // atau 8 gunting lepas.
                'not_returned' => $belum['total'],
                'not_returned_sets' => $belum['sets'],
                'not_returned_units' => $belum['units'],
                'overdue' => $this->angka->terlambatKembali(),
            ],
            'room_chart' => $this->grafikRuanganHarian($dari, $sampai),
            'open_loans' => $this->angka->peminjamanTerbuka(),
        ]);
    }

    /**
     * Rentang tanggal dari query string, bawaan BULAN BERJALAN.
     *
     * Rentang terbalik ditukar diam-diam alih-alih ditolak — hasilnya sudah pasti
     * yang dimaksud pengguna, dan galat validasi untuk hal ini cuma menghalangi.
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

    /**
     * Order peminjaman dalam rentang tanggal, tanpa yang dibatalkan.
     *
     * Disaring lewat `order_date` (kapan alatnya dipinjam), bukan `created_at`:
     * order kerap dicatat menyusul, dan yang dilaporkan adalah kejadiannya.
     */
    private function orderRentang(CarbonImmutable $dari, CarbonImmutable $sampai)
    {
        return Order::query()
            ->whereNull('canceled_at')
            ->whereDate('order_date', '>=', $dari->format('Y-m-d'))
            ->whereDate('order_date', '<=', $sampai->format('Y-m-d'));
    }

    /**
     * Jumlah UNIT alat yang dipinjam pada periode — pendamping "period_orders".
     *
     * Dua angka ini sengaja dipajang berdampingan: 12 order bisa berarti 12 alat
     * atau 90 alat, dan tanpa angka unit besaran pekerjaannya tidak terbaca.
     */
    private function unitPeriode(CarbonImmutable $dari, CarbonImmutable $sampai): int
    {
        return (int) $this->orderRentang($dari, $sampai)
            ->withCount('items')
            ->get()
            ->sum('items_count');
    }

    /**
     * Peminjaman per HARI, dipecah per RUANGAN — bahan grafik batang bertumpuk.
     *
     * Menggantikan grafik harian satu seri yang lama. Total per hari tetap terbaca
     * (tinggi seluruh batang), tapi sekarang juga terjawab pertanyaan yang
     * sebenarnya ditanyakan orang saat melihat lonjakan: RUANGAN MANA. Satu batang
     * per hari, bukan batang berdampingan per ruangan, karena dengan 31 hari ×
     * beberapa ruangan batang berdampingan menjadi terlalu tipis untuk diarahkan
     * kursor.
     *
     * Hari tanpa peminjaman tetap dikirim bernilai 0 supaya sumbu waktunya jujur:
     * akhir pekan yang kosong harus terlihat sebagai jeda, bukan hilang.
     *
     * Hanya [MAX_SERI_RUANGAN] ruangan teratas yang jadi seri sendiri; sisanya
     * dilipat ke "Lainnya" — bukan karena datanya tidak muat, tapi karena legenda
     * dengan belasan warna sudah tidak bisa dibaca dan warnanya sendiri tidak lagi
     * bisa dibedakan.
     *
     * Urutan serinya TETAP menurut total periode ini dan dikirim dari sini, bukan
     * disusun ulang di layar: warna mengikuti ruangan, dan kalau layar mengurutkan
     * sendiri, dua kartu di halaman yang sama bisa mengecat ruangan yang sama
     * dengan warna berbeda.
     *
     * @return array{rooms: array<int,array<string,mixed>>, points: array<int,array<string,mixed>>}
     */
    private function grafikRuanganHarian(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $baris = $this->orderRentang($dari, $sampai)
            ->with('room:id,name,code')
            ->get(['id', 'room_id', 'order_date']);

        // Peringkat ruangan dihitung dari SELURUH periode, bukan per hari: seri
        // yang muncul-hilang tiap hari akan mengganti warna di tengah grafik.
        $totalRuangan = [];
        foreach ($baris as $order) {
            $kunci = $order->room_id === null ? self::KUNCI_LAINNYA : 'r'.$order->room_id;
            $totalRuangan[$kunci] = ($totalRuangan[$kunci] ?? 0) + 1;
        }
        arsort($totalRuangan);

        $namaRuangan = $baris
            ->pluck('room')
            ->filter()
            ->keyBy(fn ($r) => 'r'.$r->id)
            ->map(fn ($r) => $r->name)
            ->all();

        // Order tanpa ruangan langsung masuk "Lainnya" — jangan diberi seri sendiri
        // yang tak bernama.
        $teratas = collect($totalRuangan)
            ->reject(fn ($_, $kunci) => $kunci === self::KUNCI_LAINNYA)
            ->take(self::MAX_SERI_RUANGAN)
            ->keys()
            ->all();

        $adaLainnya = count($totalRuangan) > count($teratas);

        $seri = collect($teratas)
            ->map(fn ($kunci) => [
                'key' => $kunci,
                'name' => $namaRuangan[$kunci] ?? 'Ruangan',
                'total' => (int) $totalRuangan[$kunci],
            ])
            ->all();

        if ($adaLainnya) {
            $sisa = collect($totalRuangan)->except($teratas)->sum();
            $seri[] = ['key' => self::KUNCI_LAINNYA, 'name' => 'Lainnya', 'total' => (int) $sisa];
        }

        $kunciSeri = array_column($seri, 'key');

        // Hitung per hari, lalu tuang ke kerangka tanggal yang lengkap.
        $perHari = [];
        foreach ($baris as $order) {
            $tanggal = $order->order_date?->format('Y-m-d');
            if ($tanggal === null) {
                continue;
            }

            $kunci = $order->room_id === null ? self::KUNCI_LAINNYA : 'r'.$order->room_id;
            if (! in_array($kunci, $kunciSeri, true)) {
                $kunci = self::KUNCI_LAINNYA;
            }

            $perHari[$tanggal][$kunci] = ($perHari[$tanggal][$kunci] ?? 0) + 1;
        }

        $points = [];
        for ($hari = $dari; $hari->lte($sampai); $hari = $hari->addDay()) {
            $tanggal = $hari->format('Y-m-d');
            $nilai = [];
            foreach ($kunciSeri as $kunci) {
                $nilai[$kunci] = (int) ($perHari[$tanggal][$kunci] ?? 0);
            }

            $points[] = [
                'date' => $tanggal,
                'day' => $hari->day,
                'total' => array_sum($nilai),
                'values' => $nilai,
            ];
        }

        return ['rooms' => $seri, 'points' => $points];
    }
}
