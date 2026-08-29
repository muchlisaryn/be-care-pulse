<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Traits\SummarizesOrders;
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
 * Angkanya diambil dari `SummarizesOrders`, trait yang sama dengan Dashboard
 * CSSD — dua layar ini menjawab pertanyaan yang sama dari sisi berbeda, jadi
 * angkanya tidak boleh berbeda.
 */
class NurseDashboardController extends Controller
{
    use SummarizesOrders;

    /** Banyaknya peminjaman terbuka yang ditampilkan di daftar tindak lanjut. */
    private const LIMIT_DAFTAR = 8;

    /**
     * GET /api/nurse/dashboard
     *
     * Query: `date_from`, `date_to` (Y-m-d) — bawaan bulan berjalan, hanya
     * membatasi grafik dan angka "total pinjam" pada periode itu.
     *
     * Kartu "sedang dipinjam" dan "belum dikembalikan" SELALU keadaan saat ini:
     * alat yang dipinjam bulan lalu dan belum kembali justru yang paling perlu
     * terlihat, dan menyaringnya per bulan akan menyembunyikannya.
     */
    public function index(Request $request): JsonResponse
    {
        [$dari, $sampai] = $this->rentangTanggal($request);

        return $this->success('Dashboard perawat berhasil diambil.', [
            'date_from' => $dari->format('Y-m-d'),
            'date_to' => $sampai->format('Y-m-d'),
            'summary' => [
                'period_orders' => (int) $this->orderRentang($dari, $sampai)->count(),
                'period_items' => $this->unitPeriode($dari, $sampai),
                'currently_borrowed' => $this->sedangDipinjam(),
                'not_returned' => $this->belumDikembalikan(),
                'overdue' => $this->terlambatKembali(),
            ],
            'borrow_chart' => $this->grafikHarian($dari, $sampai),
            'open_loans' => $this->peminjamanTerbuka(),
        ]);
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
     * Peminjaman yang alatnya masih di ruangan, yang paling lama dulu.
     *
     * Diurutkan dari tanggal pinjam TERLAMA, bukan terbaru: daftar ini untuk
     * ditindaklanjuti, dan yang paling lama menggantung adalah yang paling
     * mendesak. Order yang terlambat ditandai `is_overdue` agar layar tidak perlu
     * membandingkan tanggal sendiri — aturannya harus sama dengan
     * `terlambatKembali()`.
     */
    private function peminjamanTerbuka(): array
    {
        $hariIni = CarbonImmutable::now()->startOfDay();

        return Order::query()
            ->whereDerivedStatus(Order::STATUS_DIPINJAM)
            ->with('room:id,name,code')
            ->withCount([
                'items',
                'items as unreturned_items_count' => fn ($q) => $q->where('is_returned', false),
            ])
            ->orderBy('order_date')
            ->orderBy('id')
            ->limit(self::LIMIT_DAFTAR)
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'room' => $o->room?->name,
                'borrowed_by' => $o->borrowed_by,
                'order_date' => $o->order_date?->format('Y-m-d'),
                'return_plan_date' => $o->return_plan_date?->format('Y-m-d'),
                'total_items' => (int) $o->items_count,
                'unreturned_items' => (int) $o->unreturned_items_count,
                'is_overdue' => $o->return_plan_date !== null
                    && $o->return_plan_date->startOfDay()->lt($hariIni),
            ])
            ->all();
    }
}
