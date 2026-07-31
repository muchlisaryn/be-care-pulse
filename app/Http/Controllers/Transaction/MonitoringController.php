<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackagingItem;
use App\Models\ProductionItem;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    /**
     * Monitoring per ruangan: daftar unit instrumen yang sedang dipinjam
     * di tiap ruangan (order berstatus "dipinjam" & item belum dikembalikan).
     */
    public function rooms(Request $request): JsonResponse
    {
        $rooms = Room::query()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
            )
            ->with(['orders' => function ($q) {
                // dipinjam = sudah di ruangan; digudang = sudah diterima & ditujukan
                // ke ruangan (siap diantar) — keduanya dianggap "aktif" untuk ruangan.
                $q->whereIn('status', [Order::STATUS_DIPINJAM, Order::STATUS_DIGUDANG])
                    ->with(['items' => function ($q) {
                        $q->where('is_returned', false)
                            ->with(['instrumentStock.instrument', 'instrumentStock.condition']);
                    }])
                    // Baris permintaan asli — sumber JUMLAH SET yang dipinjam. `items`
                    // hanya berisi unit fisik, jadi paket 2 set × 5 instrumen tampak
                    // sebagai 10 dan jumlah setnya tidak bisa disimpulkan dari sana.
                    ->with(['requestItems.catalog']);
            }])
            ->orderBy('name')
            ->paginate(20);

        // Nomor label fisik tiap unit — supaya kolom pencarian halaman monitoring
        // bisa menemukan order dari hasil scan barcode bungkus. Dikumpulkan sekali
        // untuk seluruh halaman (bukan per ruangan) agar tidak N+1.
        $barcodeByStock = $this->barcodeNoByStock(
            collect($rooms->items())
                ->flatMap(fn (Room $room) => $room->orders->flatMap(
                    fn (Order $order) => $order->items->pluck('instrument_stock_id')
                ))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        // Kelompokkan unit yang dipinjam per (order, katalog instrumen).
        // Order 5 unit katalog yang sama -> 1 baris qty 5; single item -> qty 1.
        $rooms->getCollection()->transform(function (Room $room) use ($barcodeByStock) {
            $groups = [];
            $unitCount = 0;
            $readyCount = 0; // unit pada order "digudang" (siap diantar, belum di ruangan)
            $txKeys = [];

            foreach ($room->orders as $order) {
                // Order siap-diantar (digudang): hitung jumlahnya saja; jangan masukkan
                // ke daftar `instruments` agar "Distribusi per Ruangan" tetap = yang dipinjam.
                if ($order->status === Order::STATUS_DIGUDANG) {
                    $readyCount += $order->items->count();

                    continue;
                }

                // Jumlah SET per nama paket pada order ini, diambil dari baris
                // permintaan (quantity = jumlah set yang dipinjam). Nama katalog
                // dipakai sebagai kunci — sama dengan kunci pencocokan stok paket.
                $setsByPackage = [];
                foreach ($order->requestItems as $line) {
                    if ($line->type !== 'paket') {
                        continue;
                    }
                    $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                    $setsByPackage[$name] = ($setsByPackage[$name] ?? 0) + (int) $line->quantity;
                }

                // Paket sudah dihitung (per nama) supaya unit fisik di dalamnya
                // tidak menambah "unit dipinjam" berulang kali.
                $countedPackages = [];

                foreach ($order->items as $item) {
                    $stock = $item->instrumentStock;
                    $instrument = $stock?->instrument;
                    if (! $instrument) {
                        continue;
                    }

                    // Jumlah "unit dipinjam": paket dihitung per SET (bukan per unit
                    // fisik di dalamnya), instrumen satuan dihitung per unit.
                    if ($item->source === 'paket') {
                        $pkg = $item->package_name ?? 'Paket';
                        if (! isset($countedPackages[$pkg])) {
                            $countedPackages[$pkg] = true;
                            $unitCount += $setsByPackage[$pkg] ?? 1;
                        }
                    } else {
                        $unitCount++;
                    }
                    // Hitung transaksi unik berdasarkan no_transaction (code_transaction).
                    $txKeys[$order->code_transaction ?? ('ord-'.$order->id)] = true;
                    // Pisahkan per asal (satuan/paket) & nama paket agar bisa
                    // dikelompokkan "detail per paket" di frontend monitoring.
                    $key = $order->id.'-'.$item->source.'-'.($item->package_name ?? '').'-'.$instrument->id;

                    $groups[$key] ??= [
                        'order_code' => $order->code,
                        'code_transaction' => $order->code_transaction,
                        'borrowed_by' => $order->borrowed_by ?? $order->created_by,
                        'order_date' => $order->order_date,
                        'order_time' => optional($order->created_at)->format('H:i'),
                        'return_plan_date' => $order->return_plan_date,
                        'source' => $item->source,
                        'package_name' => $item->package_name,
                        // Jumlah SET paket ini pada order (null utk baris satuan).
                        // `qty` di bawah tetap jumlah unit fisik.
                        'package_sets' => $item->source === 'paket'
                            ? ($setsByPackage[$item->package_name ?? 'Paket'] ?? null)
                            : null,
                        'instrument' => [
                            'id' => $instrument->id,
                            'code' => $instrument->code,
                            'name' => $instrument->name,
                        ],
                        'qty' => 0,
                        'units' => [],
                    ];

                    $groups[$key]['qty']++;
                    $groups[$key]['units'][] = [
                        'instrument_stock_id' => $stock->id,
                        'code' => $stock->code,
                        'status' => $stock->status,
                        // Nomor label fisik bungkus steril unit ini (bisa null bila
                        // unit belum pernah melewati tahap packaging).
                        'barcode_no' => $barcodeByStock[(int) $stock->id] ?? null,
                        'condition' => $stock->condition
                            ? ['id' => $stock->condition->id, 'name' => $stock->condition->name]
                            : null,
                    ];
                }
            }

            $instruments = array_values($groups);

            return [
                'id' => $room->id,
                'code' => $room->code,
                'name' => $room->name,
                'borrowed_count' => $unitCount,
                // Unit yang sudah diterima & ditujukan ke ruangan ini tapi belum
                // didistribusikan (status order `digudang`).
                'ready_count' => $readyCount,
                'transaction_count' => count($txKeys),
                'instrument_count' => count($instruments),
                'instruments' => $instruments,
            ];
        });

        return $this->success('Data monitoring ruangan berhasil diambil.', $rooms);
    }

    /**
     * Nomor label fisik (`packaging_item.barcode_no`) TERBARU tiap unit, di-key oleh
     * instrument_stock_id. Label yang sudah di-void (`disabled`) diabaikan. Satu unit
     * bisa punya beberapa label lintas siklus, jadi diambil yang paling akhir.
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
     * Order masuk dari menu Order Instrumen: order yang baru diajukan
     * (belum dipinjam) — lintas user, untuk dipantau CSSD di halaman monitoring.
     */
    public function incoming(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'requestItems.instrument',
            'requestItems.catalog.items.instrument',
        ])
            ->where('status', Order::STATUS_DIAJUKAN)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest()
            ->paginate(20);

        // Sertakan total jumlah unit yang diminta (akumulasi quantity baris permintaan).
        $orders->getCollection()->transform(function (Order $order) {
            return [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
                'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
                'order_date' => $order->order_date,
                'return_plan_date' => $order->return_plan_date,
                'note' => $order->note,
                'requested_qty' => (int) $order->requestItems->sum('quantity'),
                'request_lines' => $order->requestItems->count(),
                'items' => $order->requestItems->map(fn ($it) => [
                    'type' => $it->type,
                    'name' => $it->type === 'paket'
                        ? ($it->package_name ?? $it->catalog?->name ?? 'Paket')
                        : ($it->instrument?->name ?? "Instrumen #{$it->instrument_id}"),
                    'quantity' => $it->quantity,
                    // Untuk paket: rincian instrumen di dalam satu paket (komposisi katalog).
                    'contents' => $it->type === 'paket' && $it->catalog
                        ? $it->catalog->items->map(fn ($ci) => [
                            'instrument' => $ci->instrument?->name ?? "Instrumen #{$ci->instrument_id}",
                            'code' => $ci->instrument?->code,
                            'quantity' => (int) $ci->quantity,
                        ])->values()
                        : [],
                ])->values(),
            ];
        });

        return $this->success('Data order masuk berhasil diambil.', $orders);
    }

    /**
     * JUMLAH order masuk saja — sumber angka badge notifikasi di sidebar. Dipisah dari
     * `incoming()` karena dipanggil sering (saat tab kembali fokus, setelah order
     * diterima/dibatalkan/dihapus): endpoint ini hanya `count()`, tanpa memuat 20 order
     * beserta relasinya. Penyaringnya WAJIB sama persis dengan `incoming()` — kalau
     * `incoming()` berubah, ubah juga di sini agar badge tidak pernah berbeda dari
     * daftar yang ditampilkan.
     */
    public function incomingCount(): JsonResponse
    {
        return $this->success('Jumlah order masuk berhasil diambil.', [
            'count' => Order::where('status', Order::STATUS_DIAJUKAN)->count(),
        ]);
    }

    /**
     * Order yang sudah dikembalikan (selesai) — tetap dipajang di halaman monitoring
     * sebagai riwayat. Detail unit + kondisi diambil lewat endpoint scan saat dibuka.
     */
    public function returned(Request $request): JsonResponse
    {
        $orders = Order::with(['room', 'user'])
            ->withCount('items')
            ->where('status', Order::STATUS_DIKEMBALIKAN)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest('updated_at')
            ->paginate(20);

        $orders->getCollection()->transform(fn (Order $order) => [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            'order_date' => $order->order_date,
            'return_plan_date' => $order->return_plan_date,
            // Perkiraan waktu pengembalian selesai = terakhir kali order diperbarui.
            'returned_at' => $order->updated_at,
            'total_units' => (int) $order->items_count,
        ]);

        return $this->success('Data order dikembalikan berhasil diambil.', $orders);
    }

    /**
     * Papan monitor (display TV): daftar order aktif yang dipajang di layar gudang.
     * Item dikelompokkan per (order, instrumen) lalu dihitung jumlah unitnya (QTY).
     */
    public function board(): JsonResponse
    {
        // Seluruh tahap pipeline aktif (selain dikembalikan / dibatalkan) agar papan
        // TV menampilkan setiap order beserta "lagi proses apa" (status tahapnya).
        $statuses = [
            Order::STATUS_DIAJUKAN,
            Order::STATUS_PENCUCIAN,
            Order::STATUS_PENGEMASAN,
            Order::STATUS_SELESAI,
            Order::STATUS_STERILISASI,
            Order::STATUS_STERIL,
            Order::STATUS_DIGUDANG,
            Order::STATUS_DIPINJAM,
        ];
        $stageOrder = array_flip($statuses);

        // Tahap dengan unit fisik sudah final → baca dari items; sebelum itu → requestItems.
        $packedStatuses = [
            Order::STATUS_SELESAI,
            Order::STATUS_STERILISASI,
            Order::STATUS_STERIL,
            Order::STATUS_DIGUDANG,
            Order::STATUS_DIPINJAM,
        ];

        $orders = Order::query()
            ->whereIn('status', $statuses)
            ->with([
                'room',
                'items.instrumentStock.instrument',
                'requestItems.instrument',
                'requestItems.catalog',
            ])
            ->get()
            ->sortBy(fn (Order $o) => sprintf('%02d-%s-%06d', $stageOrder[$o->status] ?? 99, (string) $o->order_date, $o->id))
            ->values();

        // Penanda SET tiap unit paket: satu set = satu `package_no` dalam satu batch
        // produksi (semua unit isi set berbagi nomor yang sama). Dipetakan sekali
        // untuk seluruh papan agar tidak query per order.
        $setKeyByStock = $this->setKeyByStock(
            $orders->flatMap(fn (Order $o) => $o->items->where('source', 'paket')->pluck('instrument_stock_id'))
                ->filter()->unique()->values()->all()
        );

        $rows = $orders->map(function (Order $order) use ($packedStatuses, $setKeyByStock) {
            $lines = [];

            // QTY papan monitor: PAKET dihitung per SET, SATUAN per unit fisik.
            // Baris permintaan dipakai sebagai cadangan bila set tidak bisa
            // disimpulkan dari unit (lihat pemakaian $setKeyByStock di bawah).
            $setsByPackage = [];
            foreach ($order->requestItems as $line) {
                if ($line->type !== 'paket') {
                    continue;
                }
                $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                $setsByPackage[$name] = ($setsByPackage[$name] ?? 0) + (int) $line->quantity;
            }

            if (in_array($order->status, $packedStatuses, true) && $order->items->isNotEmpty()) {
                $paket = [];
                $paketSetKeys = [];
                $satuan = [];
                foreach ($order->items as $it) {
                    if ($it->is_returned) {
                        continue;
                    }
                    if ($it->source === 'paket') {
                        $name = $it->package_name ?? 'Paket';
                        $paket[$name] = ($paket[$name] ?? 0) + 1;
                        // Kumpulkan penanda set unit ini; jumlah set = penanda unik.
                        if ($key = $setKeyByStock[(int) $it->instrument_stock_id] ?? null) {
                            $paketSetKeys[$name][$key] = true;
                        }
                    } else {
                        $name = $it->instrumentStock?->instrument?->name ?? '—';
                        $satuan[$name] = ($satuan[$name] ?? 0) + 1;
                    }
                }
                // Angka paket SELALU jumlah set, tidak pernah jumlah unit:
                //  1. dari penanda set unit yang benar-benar masih dipinjam — ini yang
                //     tetap benar untuk order pinjam-alih (tidak punya baris permintaan)
                //     maupun pengembalian sebagian;
                //  2. cadangan: jumlah yang diminta di baris permintaan;
                //  3. jalan terakhir: 1 set, supaya tidak pernah memajang jumlah unit
                //     dengan satuan "set".
                foreach ($paket as $name => $unitQty) {
                    $sets = count($paketSetKeys[$name] ?? []);
                    $lines[] = [
                        'jenis' => 'Paket',
                        'name' => $name,
                        'qty' => $sets > 0 ? $sets : ($setsByPackage[$name] ?? 1),
                    ];
                }
                foreach ($satuan as $name => $qty) {
                    $lines[] = ['jenis' => 'Satuan', 'name' => $name, 'qty' => $qty];
                }
            } else {
                foreach ($order->requestItems as $line) {
                    if ($line->type === 'paket') {
                        $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                        $lines[] = ['jenis' => 'Paket', 'name' => $name, 'qty' => (int) $line->quantity];
                    } else {
                        $lines[] = ['jenis' => 'Satuan', 'name' => $line->instrument?->name ?? '—', 'qty' => (int) $line->quantity];
                    }
                }
            }

            return [
                'order_code' => $order->code,
                'no_transaction' => $order->code_transaction,
                'borrowed_by' => $order->borrowed_by,
                'order_date' => optional($order->order_date)->toDateString(),
                'order_time' => optional($order->created_at)->format('H:i'),
                'room_id' => $order->room?->id,
                'room_name' => $order->room?->name,
                'status' => $order->status,
                'lines' => $lines,
            ];
        })->values();

        return $this->success('Data papan monitoring berhasil diambil.', $rows);
    }

    /**
     * Peta `instrument_stock_id` → penanda SET unit itu, dipakai papan monitor untuk
     * menghitung jumlah set (bukan jumlah unit) pada baris paket.
     *
     * Penanda = `production_id|package_no` dari snapshot production_item: seluruh
     * unit dalam satu set berbagi `package_no` yang sama, dan `production_id` ikut
     * dibawa karena nomor itu hanya unik DI DALAM satu batch produksi.
     *
     * Unit tanpa `package_no` (data lama sebelum kolom itu ada) sengaja tidak
     * dimasukkan — pemanggil jatuh ke baris permintaan, bukan menghitung unit.
     *
     * @param  array<int,int>  $stockIds
     * @return array<int,string>
     */
    private function setKeyByStock(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return ProductionItem::whereIn('instrument_stock_id', $stockIds)
            ->whereNotNull('package_no')
            ->orderBy('id')
            ->get(['instrument_stock_id', 'production_id', 'package_no'])
            // Urut id ASC → batch terbaru menimpa yang lama, sama seperti pembacaan
            // nama/kode unit di tempat lain.
            ->mapWithKeys(fn ($pi) => [
                (int) $pi->instrument_stock_id => $pi->production_id.'|'.$pi->package_no,
            ])
            ->all();
    }
}
