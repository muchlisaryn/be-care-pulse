<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStorage;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tahap 5 — Penyimpanan (Storage Management). Menempatkan unit steril ke lokasi
 * rak penyimpanan & memantau masa kedaluwarsa (early-warning).
 */
class StorageController extends Controller
{
    /** Ambang hari early-warning kedaluwarsa (alert merah). */
    private const EXPIRY_ALERT_DAYS = 7;

    /**
     * Order steril yang perlu disimpan (status `steril`). Mengembalikan unit +
     * info apakah tiap unit sudah ditempatkan di rak & masa kedaluwarsanya.
     */
    public function incoming(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'items.instrumentStock.instrument',
            'storages' => fn ($q) => $q->where('status', InstrumentStorage::STATUS_TERSIMPAN),
            'sterilizations' => fn ($q) => $q->where('status', 'selesai')->latest(),
        ])
            ->where('status', Order::STATUS_STERIL)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('code_transaction', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->orderByDesc('processed_at')
            ->latest()
            ->paginate(20);

        $orders->getCollection()->transform(fn (Order $order) => $this->incomingPayload($order));

        return $this->success('Data order siap disimpan berhasil diambil.', $orders);
    }

    /**
     * Simpan unit-unit order ke rak gudang steril. Body `items`:
     * [{ instrument_stock_id, rack_code }]. Bila SELURUH unit order sudah
     * tersimpan, order → `digudang`.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_STERIL) {
            return $this->error('Order ini belum steril / tidak siap disimpan.', 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.instrument_stock_id' => 'required|integer|exists:instrument_stocks,id',
            'items.*.rack_code' => 'required|string|max:255',
        ]);

        // Unit fisik order ini (yang belum dikembalikan) — hanya ini yang boleh disimpan.
        $orderStockIds = $order->items()->where('is_returned', false)
            ->pluck('instrument_stock_id')->all();

        $batch = $order->sterilizations()->where('status', 'selesai')->latest()->first();
        $expiry = $batch?->expiry_date;

        // Unit yang sudah tersimpan sebelumnya (hindari duplikat).
        $alreadyStored = InstrumentStorage::where('order_id', $order->id)
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->pluck('instrument_stock_id')->all();

        try {
            DB::transaction(function () use ($validated, $order, $orderStockIds, $alreadyStored, $batch, $expiry) {
                foreach ($validated['items'] as $item) {
                    $stockId = (int) $item['instrument_stock_id'];

                    // Abaikan unit yang bukan milik order atau sudah tersimpan.
                    if (! in_array($stockId, $orderStockIds, true) || in_array($stockId, $alreadyStored, true)) {
                        continue;
                    }

                    InstrumentStorage::create([
                        'order_id' => $order->id,
                        'sterilization_id' => $batch?->id,
                        'instrument_stock_id' => $stockId,
                        'rack_code' => $item['rack_code'],
                        'expiry_date' => $expiry,
                        'status' => InstrumentStorage::STATUS_TERSIMPAN,
                        'stored_at' => now(),
                    ]);
                    $alreadyStored[] = $stockId;
                }

                // Bila seluruh unit order sudah tersimpan → order masuk gudang steril.
                if (count(array_intersect($orderStockIds, $alreadyStored)) >= count($orderStockIds)) {
                    $order->status = Order::STATUS_DIGUDANG;
                    $order->save();
                    OrderEvent::record(OrderEvent::TYPE_DISIMPAN, $order, [
                        'note' => 'Seluruh unit tersimpan di gudang steril',
                    ]);
                }
            });

            $order->load([
                'items.instrumentStock.instrument',
                'storages' => fn ($q) => $q->where('status', InstrumentStorage::STATUS_TERSIMPAN),
                'sterilizations' => fn ($q) => $q->where('status', 'selesai')->latest(),
            ]);

            return $this->success('Unit berhasil disimpan ke gudang steril.', $this->incomingPayload($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Inventaris real-time gudang steril: unit yang sedang tersimpan + lokasi rak +
     * status kedaluwarsa (alert merah bila ≤ ambang hari atau sudah lewat).
     * ?days= ambang early-warning (default 7).
     */
    public function inventory(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));

        $rows = InstrumentStorage::with(['instrumentStock.instrument', 'order'])
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('rack_code', 'like', "%{$s}%")
                    ->orWhereHas('instrumentStock', fn ($u) => $u->where('code', 'like', "%{$s}%"))
                    ->orWhereHas('instrumentStock.instrument', fn ($i) => $i->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('order', fn ($o) => $o->where('code', 'like', "%{$s}%")
                        ->orWhere('code_transaction', 'like', "%{$s}%")))
            )
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(20);

        $rows->getCollection()->transform(fn (InstrumentStorage $s) => $this->inventoryRow($s, $days));

        return $this->success('Inventaris gudang steril berhasil diambil.', $rows);
    }

    /** Ringkasan order siap-simpan + unit & status penempatannya. */
    private function incomingPayload(Order $order): array
    {
        $units = $order->items->where('is_returned', false)->values();
        $storedByStock = $order->relationLoaded('storages')
            ? $order->storages->keyBy('instrument_stock_id')
            : collect();
        $expiry = $order->relationLoaded('sterilizations')
            ? optional($order->sterilizations->first())->expiry_date
            : null;

        $unitRows = $units->map(function ($it) use ($storedByStock) {
            $stored = $storedByStock->get($it->instrument_stock_id);

            return [
                'id' => $it->instrument_stock_id,
                'code' => $it->instrumentStock?->code,
                'instrument' => $it->instrumentStock?->instrument?->name,
                'source' => $it->source,
                'package_name' => $it->package_name,
                'stored' => (bool) $stored,
                'rack_code' => $stored?->rack_code,
            ];
        })->values();

        return [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            'processed_at' => $order->processed_at,
            'expiry_date' => $expiry,
            'unit_count' => $unitRows->count(),
            'stored_count' => $unitRows->where('stored', true)->count(),
            'units' => $unitRows,
        ];
    }

    /** Satu baris inventaris + status kedaluwarsa. */
    private function inventoryRow(InstrumentStorage $s, int $days): array
    {
        $daysToExpiry = null;
        $alert = false;
        $expired = false;

        if ($s->expiry_date) {
            $daysToExpiry = (int) now()->startOfDay()->diffInDays($s->expiry_date->copy()->startOfDay(), false);
            $expired = $daysToExpiry < 0;
            $alert = $daysToExpiry <= $days; // termasuk yang sudah lewat
        }

        return [
            'id' => $s->id,
            'rack_code' => $s->rack_code,
            'stored_at' => $s->stored_at,
            'expiry_date' => $s->expiry_date,
            'days_to_expiry' => $daysToExpiry,
            'alert' => $alert,
            'expired' => $expired,
            'unit' => [
                'id' => $s->instrument_stock_id,
                'code' => $s->instrumentStock?->code,
                'instrument' => $s->instrumentStock?->instrument?->name,
            ],
            'order' => $s->order ? [
                'id' => $s->order->id,
                'code' => $s->order->code,
                'code_transaction' => $s->order->code_transaction,
            ] : null,
        ];
    }
}
