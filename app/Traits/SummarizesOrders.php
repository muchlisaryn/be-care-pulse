<?php

namespace App\Traits;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Angka peminjaman yang dipakai bersama Dashboard CSSD dan Dashboard Perawat.
 *
 * Ditaruh di satu tempat KARENA kedua dashboard menjawab pertanyaan yang sama
 * dari sudut berbeda (petugas CSSD vs perawat ruangan). Kalau masing-masing
 * menghitung sendiri, cepat atau lambat satu layar bilang "12 sedang dipinjam"
 * dan layar sebelahnya bilang 11, tanpa ada yang tahu mana yang benar.
 *
 * Seluruh status di sini memakai `whereDerivedStatus()` — status TURUNAN dari
 * jejak `canceled_at` / `distributed_at` / `processed_at` / `order_item.is_returned`,
 * bukan kolom `status` yang ditulis ulang di banyak titik dan punya jalur yang
 * melewatinya sama sekali (lihat `Order::deriveStatus()`).
 */
trait SummarizesOrders
{
    /**
     * Rentang tanggal dari query string, bawaan BULAN BERJALAN.
     *
     * Dipakai seragam oleh semua dashboard: `date_from` & `date_to` (Y-m-d).
     * Rentang terbalik ditukar diam-diam alih-alih ditolak — hasilnya sudah pasti
     * yang dimaksud pengguna, dan galat validasi untuk hal ini cuma menghalangi.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function rentangTanggal(Request $request): array
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
    protected function orderRentang(CarbonImmutable $dari, CarbonImmutable $sampai)
    {
        return Order::query()
            ->whereNull('canceled_at')
            ->whereDate('order_date', '>=', $dari->format('Y-m-d'))
            ->whereDate('order_date', '<=', $sampai->format('Y-m-d'));
    }

    /**
     * Jumlah peminjaman per hari sepanjang rentang — bahan grafik batang.
     *
     * Hari tanpa peminjaman tetap dikirim bernilai 0 supaya sumbu waktunya
     * jujur: akhir pekan yang kosong harus terlihat sebagai jeda, bukan hilang.
     *
     * Rentang yang sangat panjang tidak dipotong di sini; pemanggilnya yang
     * membatasi (bawaan satu bulan = maksimal 31 titik).
     */
    protected function grafikHarian(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $baris = $this->orderRentang($dari, $sampai)
            ->selectRaw('DATE(order_date) as tanggal, COUNT(*) as jumlah')
            ->groupBy('tanggal')
            ->pluck('jumlah', 'tanggal');

        $hasil = [];
        for ($hari = $dari; $hari->lte($sampai); $hari = $hari->addDay()) {
            $kunci = $hari->format('Y-m-d');
            $hasil[] = [
                'date' => $kunci,
                'day' => $hari->day,
                'total' => (int) ($baris[$kunci] ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * Order yang alatnya masih ada di ruangan SAAT INI.
     *
     * SENGAJA tidak dibatasi rentang tanggal: pertanyaannya "berapa yang sedang
     * dipinjam sekarang", dan alat yang dipinjam bulan lalu tapi belum kembali
     * justru yang paling perlu terlihat. Menyaringnya per bulan akan
     * menyembunyikan tunggakan yang paling lama persis saat ia paling penting.
     */
    protected function sedangDipinjam(): int
    {
        return (int) Order::query()->whereDerivedStatus(Order::STATUS_DIPINJAM)->count();
    }

    /**
     * Order masuk yang belum diproses CSSD — status turunan `diajukan`.
     *
     * Sama seperti `sedangDipinjam()`, ini angka "saat ini", bukan per periode:
     * ini antrean kerja, bukan laporan.
     */
    protected function orderMasuk(): int
    {
        return (int) Order::query()->whereDerivedStatus(Order::STATUS_DIAJUKAN)->count();
    }

    /**
     * Unit alat yang sudah keluar tapi belum ditandai kembali.
     *
     * Dihitung per UNIT (`order_item`), bukan per order — satu order bisa
     * membawa sepuluh alat dan yang kembali baru tiga; angka per order akan
     * menyembunyikan tujuh sisanya.
     *
     * Hanya order yang benar-benar sudah didistribusikan yang dihitung: unit
     * pada order yang masih mengantre di CSSD belum pernah keluar, jadi belum
     * bisa disebut "belum dikembalikan".
     */
    protected function belumDikembalikan(): int
    {
        return (int) OrderItem::query()
            ->where('is_returned', false)
            ->whereHas('order', fn ($q) => $q
                ->whereNull('canceled_at')
                ->whereNotNull('distributed_at')
                ->whereNull('return_actual_date'))
            ->count();
    }

    /**
     * Peminjaman yang lewat tanggal rencana kembali dan alatnya masih di luar.
     *
     * Dipisahkan dari `belumDikembalikan()` karena maknanya berbeda: yang ini
     * sudah terlambat, dan itulah yang perlu ditagih.
     */
    protected function terlambatKembali(): int
    {
        return (int) Order::query()
            ->whereDerivedStatus(Order::STATUS_DIPINJAM)
            ->whereNotNull('return_plan_date')
            ->whereDate('return_plan_date', '<', CarbonImmutable::now()->format('Y-m-d'))
            ->count();
    }
}
