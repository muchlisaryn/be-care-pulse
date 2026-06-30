<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
                    }]);
            }])
            ->orderBy('name')
            ->paginate(20);

        // Kelompokkan unit yang dipinjam per (order, katalog instrumen).
        // Order 5 unit katalog yang sama -> 1 baris qty 5; single item -> qty 1.
        $rooms->getCollection()->transform(function (Room $room) {
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

                foreach ($order->items as $item) {
                    $stock = $item->instrumentStock;
                    $instrument = $stock?->instrument;
                    if (! $instrument) {
                        continue;
                    }

                    $unitCount++;
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

        $rows = $orders->map(function (Order $order) use ($packedStatuses) {
            $lines = [];

            if (in_array($order->status, $packedStatuses, true) && $order->items->isNotEmpty()) {
                $paket = [];
                $satuan = [];
                foreach ($order->items as $it) {
                    if ($it->is_returned) {
                        continue;
                    }
                    if ($it->source === 'paket') {
                        $name = $it->package_name ?? 'Paket';
                        $paket[$name] = ($paket[$name] ?? 0) + 1;
                    } else {
                        $name = $it->instrumentStock?->instrument?->name ?? '—';
                        $satuan[$name] = ($satuan[$name] ?? 0) + 1;
                    }
                }
                foreach ($paket as $name => $qty) {
                    $lines[] = ['jenis' => 'Paket', 'name' => $name, 'qty' => $qty];
                }
                foreach ($satuan as $name => $qty) {
                    $lines[] = ['jenis' => 'Satuan', 'name' => $name, 'qty' => $qty];
                }
            } else {
                foreach ($order->requestItems as $line) {
                    if ($line->type === 'paket') {
                        $name = $line->package_name ?? $line->catalog?->name ?? 'Paket';
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
}
