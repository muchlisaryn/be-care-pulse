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
                $q->where('status', Order::STATUS_DIPINJAM)
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

            foreach ($room->orders as $order) {
                foreach ($order->items as $item) {
                    $stock = $item->instrumentStock;
                    $instrument = $stock?->instrument;
                    if (! $instrument) {
                        continue;
                    }

                    $unitCount++;
                    // Pisahkan per asal (satuan/paket) & nama paket agar bisa
                    // dikelompokkan "detail per paket" di frontend monitoring.
                    $key = $order->id.'-'.$item->source.'-'.($item->package_name ?? '').'-'.$instrument->id;

                    $groups[$key] ??= [
                        'order_code' => $order->code,
                        'code_transaction' => $order->code_transaction,
                        'borrowed_by' => $order->borrowed_by ?? $order->created_by,
                        'order_date' => $order->order_date,
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
        $orders = Order::query()
            ->whereIn('status', [
                Order::STATUS_DIAJUKAN,
                Order::STATUS_DIPINJAM,
            ])
            ->with(['room', 'items.instrumentStock.instrument'])
            ->orderBy('order_date')
            ->orderBy('created_at')
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            // Kelompokkan item per instrumen agar QTY-nya tergabung.
            $groups = [];
            foreach ($order->items as $item) {
                $instrument = $item->instrumentStock?->instrument;
                if (! $instrument) {
                    continue;
                }

                $key = $instrument->id;
                $groups[$key] ??= [
                    'instrument_code' => $instrument->code,
                    'instrument_name' => $instrument->name,
                    'qty' => 0,
                ];
                $groups[$key]['qty']++;
            }

            foreach ($groups as $g) {
                $rows[] = [
                    'status' => $order->status,
                    'date' => optional($order->order_date)->format('d.m.Y'),
                    'time' => optional($order->created_at)->format('H:i'),
                    'reservation' => $order->code,
                    'room_code' => $order->room?->code,
                    'room_name' => $order->room?->name,
                    'instrument_code' => $g['instrument_code'],
                    'instrument_name' => $g['instrument_name'],
                    'qty' => $g['qty'],
                    'unit' => 'PCS',
                ];
            }
        }

        return $this->success('Data papan monitoring berhasil diambil.', $rows);
    }
}
