<?php

namespace App\Services\Dashboard;

use App\Models\InstrumentStorage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequestItem;
use App\Models\PackagingItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Angka peminjaman KHUSUS Dashboard Perawat.
 *
 * SENGAJA berdiri sendiri, tidak memakai trait `SummarizesOrders` yang dipakai
 * bersama Dashboard CSSD: dua layar itu menjawab pertanyaan berbeda, dan begitu
 * aturan "belum dikembalikan" di sini diperbaiki, angka Dashboard CSSD ikut
 * bergeser tanpa ada yang meminta. Sumbernya dipisah supaya tiap layar bisa
 * dikoreksi sendiri-sendiri — alasan yang sama dengan dipisahnya
 * `TrackingCountController` dari `MonitoringController@counts`.
 *
 * DUA HAL yang diperbaiki di sini dibanding perhitungan lama:
 *
 * 1. PEMICUNYA sama persis dengan tab "Distribution & Tracking"
 *    (`/cssd/tracking-order?tab=distribusi`): order belum dibatalkan, SUDAH
 *    didistribusikan, dan masih punya minimal satu unit `is_returned = false`.
 *    Perhitungan lama juga menuntut `return_actual_date` masih kosong, sehingga
 *    order yang dikembalikan SEBAGIAN (tanggal kembali di header sudah terisi,
 *    tapi sebagian unit masih di ruangan) hilang dari kartu "belum dikembalikan"
 *    padahal tetap terpampang di tab distribusi. Dua layar, dua angka, tanpa ada
 *    yang tahu mana yang benar.
 *
 * 2. HITUNGANNYA per PAKET, bukan per unit satuan. Satu paket berisi sepuluh
 *    instrumen dipinjam dan dikembalikan sebagai SATU bungkus, jadi menghitungnya
 *    sepuluh membuat kartu meledak jadi angka ratusan yang tidak bisa
 *    ditindaklanjuti. Aturannya sama dengan daftar monitoring: satu nomor label
 *    kemasan (`packaging_item.barcode_no`) = satu bungkus = satu set; baris
 *    `satuan` tetap dihitung per unit fisik karena memang dipinjam satuan.
 */
class NurseLoanFigures
{
    /** Banyaknya peminjaman terbuka yang ditampilkan di daftar tindak lanjut. */
    private const LIMIT_DAFTAR = 8;

    /**
     * Order yang alatnya masih di ruangan SAAT INI — definisi tunggal yang dipakai
     * seluruh kelas ini.
     *
     * Disaring dari jejak waktu (`canceled_at`, `distributed_at`) dan tanda per unit
     * (`order_item.is_returned`), BUKAN dari kolom `status`: status ditulis ulang di
     * banyak titik sepanjang alur CSSD dan bisa tertinggal, sedangkan ketiga jejak
     * itu masing-masing hanya ditulis sekali tepat saat kejadiannya.
     *
     * SENGAJA tanpa batas tanggal: pertanyaannya "apa yang masih di luar sekarang",
     * dan alat yang dipinjam bulan lalu justru yang paling perlu terlihat.
     */
    public function outstandingOrders(): Builder
    {
        return $this->applyOutstanding(Order::query());
    }

    /** Berapa ORDER yang alatnya masih di ruangan. */
    public function sedangDipinjam(): int
    {
        return (int) $this->outstandingOrders()->count();
    }

    /** Order yang sudah lewat tanggal rencana kembali dan alatnya masih di luar. */
    public function terlambatKembali(): int
    {
        return (int) $this->outstandingOrders()
            ->whereNotNull('return_plan_date')
            ->whereDate('return_plan_date', '<', CarbonImmutable::now()->format('Y-m-d'))
            ->count();
    }

    /**
     * Yang belum dikembalikan, DIKELOMPOKKAN: berapa set paket + berapa unit satuan.
     *
     * `total` (set + unit) adalah angka yang dipajang kartu; rinciannya ikut dikirim
     * karena "8" saja tidak memberi tahu apakah itu 8 bungkus paket atau 8 gunting.
     *
     * @return array{sets: int, units: int, total: int}
     */
    public function belumDikembalikan(): array
    {
        $items = $this->itemQuery()
            ->where('is_returned', false)
            ->whereHas('order', fn ($q) => $this->applyOutstanding($q))
            ->get();

        return $this->countAsSetsAndUnits($items);
    }

    /**
     * Peminjaman yang alatnya masih di ruangan, yang paling lama menggantung dulu.
     *
     * Diurutkan dari tanggal pinjam TERLAMA, bukan terbaru: daftar ini untuk
     * ditindaklanjuti. Kolom jumlahnya memakai satuan yang sama dengan kartu — set
     * paket + unit satuan — supaya "3 / 5" di tabel bisa dibandingkan langsung
     * dengan angka di atasnya.
     *
     * @return array<int,array<string,mixed>>
     */
    public function peminjamanTerbuka(): array
    {
        $hariIni = CarbonImmutable::now()->startOfDay();

        $orders = $this->outstandingOrders()
            ->with('room:id,name,code')
            ->orderBy('order_date')
            ->orderBy('id')
            ->limit(self::LIMIT_DAFTAR)
            ->get(['id', 'code', 'room_id', 'borrowed_by', 'order_date', 'return_plan_date']);

        if ($orders->isEmpty()) {
            return [];
        }

        // SELURUH unit order-order itu (termasuk yang sudah kembali) ditarik sekali,
        // lalu dibagi di memori: penyebut kolom "belum kembali / total" harus dihitung
        // dengan aturan set yang sama, dan itu butuh baris yang sudah kembali juga.
        $items = $this->itemQuery()
            ->whereIn('order_id', $orders->pluck('id')->all())
            ->get()
            ->groupBy('order_id');

        return $orders->map(function (Order $o) use ($items, $hariIni) {
            $milikOrder = $items->get($o->id, collect());
            $total = $this->countAsSetsAndUnits($milikOrder);
            $belum = $this->countAsSetsAndUnits($milikOrder->where('is_returned', false));

            return [
                'id' => $o->id,
                'code' => $o->code,
                'room' => $o->room?->name,
                'borrowed_by' => $o->borrowed_by,
                'order_date' => $o->order_date?->format('Y-m-d'),
                'return_plan_date' => $o->return_plan_date?->format('Y-m-d'),
                'total_items' => $total['total'],
                'total_sets' => $total['sets'],
                'total_units' => $total['units'],
                'unreturned_items' => $belum['total'],
                'unreturned_sets' => $belum['sets'],
                'unreturned_units' => $belum['units'],
                'is_overdue' => $o->return_plan_date !== null
                    && $o->return_plan_date->startOfDay()->lt($hariIni),
            ];
        })->all();
    }

    /**
     * Syarat "masih di ruangan" — satu aturan, dipakai baik sebagai query utama
     * maupun sebagai isi `whereHas('order', ...)`.
     */
    private function applyOutstanding($q)
    {
        return $q->whereNull('canceled_at')
            ->whereNotNull('distributed_at')
            ->whereHas('items', fn ($i) => $i->where('is_returned', false));
    }

    /** Kolom minimum yang dibutuhkan penghitung set — jangan tarik lebih. */
    private function itemQuery(): Builder
    {
        return OrderItem::query()
            ->select(['id', 'order_id', 'instrument_stock_id', 'source', 'package_name', 'is_returned']);
    }

    /**
     * Jumlah menurut aturan tampilan: baris `paket` dihitung per SET, baris `satuan`
     * per unit fisik.
     *
     * Satu set = satu nomor label kemasan berbeda di dalam satu paket pada satu
     * order. Bila seluruh unit sebuah paket belum punya nomor label (data lama,
     * sebelum tahap packaging), jumlahnya jatuh ke baris permintaan lalu ke 1 —
     * JANGAN pernah jatuh ke jumlah unit, karena itu persis kesalahan yang sedang
     * diperbaiki di sini.
     *
     * @param  Collection<int,OrderItem>  $items
     * @return array{sets: int, units: int, total: int}
     */
    private function countAsSetsAndUnits(Collection $items): array
    {
        if ($items->isEmpty()) {
            return ['sets' => 0, 'units' => 0, 'total' => 0];
        }

        $barcodes = $this->barcodeNoByOrderStock($items);
        $fallback = $this->requestedSetsByOrder($items->pluck('order_id')->all());

        $units = 0;
        $labels = [];

        foreach ($items as $item) {
            if ($item->source !== 'paket') {
                $units++;

                continue;
            }

            $key = $item->order_id.'|'.($item->package_name ?? 'Paket');
            $labels[$key] ??= [];
            if ($barcode = $barcodes[$item->order_id.'|'.(int) $item->instrument_stock_id] ?? null) {
                $labels[$key][$barcode] = true;
            }
        }

        $sets = 0;
        foreach ($labels as $key => $daftar) {
            $sets += count($daftar) > 0 ? count($daftar) : ($fallback[$key] ?? 1);
        }

        return ['sets' => $sets, 'units' => $units, 'total' => $sets + $units];
    }

    /**
     * Nomor label kemasan tiap unit PADA SIKLUS ORDER-nya, di-key
     * `"{order_id}|{instrument_stock_id}"`.
     *
     * Sumber utamanya jejak gudang steril order tsb, BUKAN label terakhir milik unit:
     * unit yang sudah dikemas ulang untuk order berikutnya membawa nomor label baru,
     * dan itu akan memecah satu set order lama jadi dua bungkus. Label terbaru cuma
     * cadangan untuk unit tanpa jejak gudang (data lama / pinjam-alih antar ruangan).
     *
     * @param  Collection<int,OrderItem>  $items
     * @return array<string,string>
     */
    private function barcodeNoByOrderStock(Collection $items): array
    {
        $siklus = InstrumentStorage::packagingBarcodeMapByOrders(
            $items->pluck('order_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
        $terbaru = $this->barcodeNoByStock(
            $items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        $map = [];

        foreach ($items as $item) {
            $stockId = (int) $item->instrument_stock_id;
            $barcode = $siklus[$item->order_id.'|'.$stockId] ?? $terbaru[$stockId] ?? null;

            if ($barcode !== null) {
                $map[$item->order_id.'|'.$stockId] = $barcode;
            }
        }

        return $map;
    }

    /**
     * CADANGAN label: nomor label TERBARU tiap unit. Label yang sudah di-void
     * (`disabled`) diabaikan.
     *
     * @param  array<int,int>  $stockIds
     * @return array<int,string>
     */
    private function barcodeNoByStock(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return PackagingItem::whereIn('instrument_stock_id', $stockIds)
            ->where('disabled', false)
            ->whereNotNull('barcode_no')
            ->orderByDesc('id')
            ->get(['instrument_stock_id', 'barcode_no'])
            ->groupBy('instrument_stock_id')
            ->map(fn ($g) => $g->first()->barcode_no) // orderByDesc → first = terbaru
            ->all();
    }

    /**
     * Jumlah SET paket yang DIMINTA tiap order, di-key `"{order_id}|{namaPaket}"` —
     * cadangan penghitung set untuk unit paket yang belum punya nomor label kemasan.
     *
     * @param  array<int,int>  $orderIds
     * @return array<string,int>
     */
    private function requestedSetsByOrder(array $orderIds): array
    {
        $orderIds = collect($orderIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (empty($orderIds)) {
            return [];
        }

        $map = [];
        OrderRequestItem::with('catalog:id,name')
            ->whereIn('order_id', $orderIds)
            ->where('type', 'paket')
            ->get(['id', 'order_id', 'instrument_catalog_id', 'package_name', 'quantity'])
            ->each(function (OrderRequestItem $line) use (&$map) {
                $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                $key = $line->order_id.'|'.$name;
                $map[$key] = ($map[$key] ?? 0) + (int) $line->quantity;
            });

        return $map;
    }
}
