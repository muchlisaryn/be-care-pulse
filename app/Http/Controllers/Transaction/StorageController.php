<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PipelineEvent;
use App\Models\ProductionItem;
use App\Models\Sterilization;
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
        $orderItems = $order->items()->where('is_returned', false)->get();
        $orderStockIds = $orderItems->pluck('instrument_stock_id')->all();
        $originByStock = $orderItems->keyBy('instrument_stock_id');

        $batch = $order->sterilizations()->where('status', 'selesai')->latest()->first();
        $expiry = $batch?->expiry_date;

        // Unit yang sudah tersimpan sebelumnya (hindari duplikat).
        $alreadyStored = InstrumentStorage::where('order_id', $order->id)
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->pluck('instrument_stock_id')->all();

        try {
            DB::transaction(function () use ($validated, $order, $orderStockIds, $alreadyStored, $batch, $expiry, $originByStock) {
                foreach ($validated['items'] as $item) {
                    $stockId = (int) $item['instrument_stock_id'];

                    // Abaikan unit yang bukan milik order atau sudah tersimpan.
                    if (! in_array($stockId, $orderStockIds, true) || in_array($stockId, $alreadyStored, true)) {
                        continue;
                    }

                    $origin = $originByStock->get($stockId);
                    InstrumentStorage::create([
                        'order_id' => $order->id,
                        'sterilization_id' => $batch?->id,
                        'instrument_stock_id' => $stockId,
                        'source' => $origin?->source ?? 'satuan',
                        'package_name' => $origin?->package_name,
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

                // Perbarui tahap unit (→ disimpan di rak).
                InstrumentStock::syncStages($orderStockIds);
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
     *
     * Unit yang sudah keluar gudang (didistribusikan → `dipinjam`, atau sedang
     * diproses ulang → `sterilisasi`) TIDAK ditampilkan meski baris gudangnya masih
     * `tersimpan`: yang ditampilkan hanya unit yang fisiknya benar-benar ada di rak,
     * yaitu yang kondisinya `tersedia`. Barisnya tetap ada di database (tidak dihapus).
     */
    public function inventory(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));

        $rows = InstrumentStorage::with(['instrumentStock.instrument', 'order', 'sterilization'])
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->whereHas(
                'instrumentStock',
                fn ($q) => $q->where('status', InstrumentStock::STATUS_TERSEDIA)
            )
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

        $codes = $this->productionCodeMap(
            $rows->getCollection()->pluck('instrument_stock_id')->map(fn ($id) => (int) $id)->all()
        );

        $rows->getCollection()->transform(fn (InstrumentStorage $s) => $this->inventoryRow($s, $days, $codes));

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

        $packageImages = $this->packageImages(
            $units->where('source', 'paket')->pluck('package_name')
        );

        $unitRows = $units->map(function ($it) use ($storedByStock, $packageImages) {
            $stored = $storedByStock->get($it->instrument_stock_id);

            return [
                'id' => $it->instrument_stock_id,
                'code' => $it->instrumentStock?->code,
                'instrument' => $it->instrumentStock?->instrument?->name,
                'image_url' => $it->instrumentStock?->instrument?->image_url,
                'source' => $it->source,
                'package_name' => $it->package_name,
                'package_image' => $it->source === 'paket' ? ($packageImages[$it->package_name] ?? null) : null,
                'stored' => (bool) $stored,
                'rack_code' => $stored?->rack_code,
            ];
        })->values();

        return [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'source' => 'order',
            'store_url' => "/master/orders/{$order->id}/store",
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            'processed_at' => $order->processed_at,
            'expiry_date' => $expiry,
            'unit_count' => $unitRows->count(),
            'stored_count' => $unitRows->where('stored', true)->count(),
            'units' => $unitRows,
        ];
    }

    /**
     * Batch steril PIPELINE PRODUKSI yang perlu disimpan: sterilisasi `selesai`
     * milik pipeline produksi (tanpa `order_id`) — dulu ditandai keberadaan
     * `packaging_code` yang kini sudah dihapus dari header. Bentuk respons sama
     * dengan incoming order agar FE bisa memakai satu daftar & modal simpan yang
     * sama (dibedakan lewat `source` / `store_url`).
     */
    public function productionIncoming(Request $request): JsonResponse
    {
        $batches = Sterilization::with([
            'items.instrumentStock.instrument',
            'packagings.washing.production.items.instrumentStock.instrument',
        ])
            ->where('status', Sterilization::STATUS_SELESAI)
            ->whereNull('order_id')
            // Hanya batch yang MASIH punya unit menunggu ditaruh di rak. Unit yang sudah
            // pernah dibuatkan baris gudang (walau kini `keluar` karena didistribusikan)
            // atau yang fisiknya tidak lagi di CSSD (dipinjam / dikembalikan / re-proses)
            // bukan urusan penyimpanan lagi — batchnya hilang dari daftar siap-simpan.
            ->whereHas('items', function ($q) {
                $q->where(fn ($w) => $w->whereNull('result')->orWhere('result', Sterilization::RESULT_BERHASIL))
                    ->whereHas(
                        'instrumentStock',
                        fn ($s) => $s->where('status', InstrumentStock::STATUS_TERSEDIA)
                    )
                    ->whereNotExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('instrument_storages')
                            ->whereColumn('instrument_storages.instrument_stock_id', 'sterilization_items.instrument_stock_id')
                            ->whereColumn('instrument_storages.sterilization_id', 'sterilization_items.sterilization_id')
                            ->whereNull('instrument_storages.deleted_by');
                    });
            })
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('code', 'like', "%{$s}%")
            )
            ->orderByDesc('id')
            ->paginate(20);

        $batches->getCollection()->transform(fn (Sterilization $b) => $this->productionIncomingPayload($b));

        return $this->success('Data batch produksi siap-simpan berhasil diambil.', $batches);
    }

    /**
     * Simpan unit-unit batch sterilisasi PRODUKSI ke rak gudang steril. Body
     * `items`: [{ instrument_stock_id, rack_code }]. Baris gudang dibuat dengan
     * sterilization_id (tanpa order_id). Unit tetap berstatus `tersedia` (invarian
     * gudang) namun terkecuali dari pool produksi karena baris gudang `tersimpan`.
     */
    public function storeProduction(Request $request, Sterilization $sterilization): JsonResponse
    {
        if ($sterilization->status !== Sterilization::STATUS_SELESAI || $sterilization->order_id !== null) {
            return $this->error('Batch ini bukan batch produksi yang steril / siap disimpan.', 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.instrument_stock_id' => 'required|integer|exists:instrument_stocks,id',
            'items.*.rack_code' => 'required|string|max:255',
        ]);

        // Unit fisik batch ini yang BOLEH disimpan = hanya yang BERHASIL steril.
        // Unit gagal (result 'gagal') dikecualikan — mereka antre re-proses, bukan
        // masuk gudang steril. (result null = batch lama pra per-unit → dianggap berhasil.)
        $batchStockIds = $sterilization->items()
            ->where(fn ($q) => $q->whereNull('result')->orWhere('result', Sterilization::RESULT_BERHASIL))
            ->pluck('instrument_stock_id')->all();
        $expiry = $sterilization->expiry_date;

        // Asal unit (satuan/paket) dari production_item, untuk denormalisasi ke gudang.
        $sterilization->loadMissing('packagings.washing.production.items');
        $originByStock = $sterilization->packagings
            ->flatMap(fn ($p) => $p->washing?->production?->items ?? collect())
            ->keyBy('instrument_stock_id');

        // Unit yang sudah pernah disimpan (hindari duplikat). Termasuk baris `keluar`:
        // unit yang sudah didistribusikan tidak boleh dibuatkan baris gudang baru.
        $alreadyStored = InstrumentStorage::where('sterilization_id', $sterilization->id)
            ->pluck('instrument_stock_id')->all();

        try {
            DB::transaction(function () use ($validated, $sterilization, $batchStockIds, $alreadyStored, $expiry, $originByStock) {
                foreach ($validated['items'] as $item) {
                    $stockId = (int) $item['instrument_stock_id'];

                    if (! in_array($stockId, $batchStockIds, true) || in_array($stockId, $alreadyStored, true)) {
                        continue;
                    }

                    $origin = $originByStock->get($stockId);
                    InstrumentStorage::create([
                        'order_id' => null,
                        'sterilization_id' => $sterilization->id,
                        'instrument_stock_id' => $stockId,
                        'source' => $origin?->source ?? 'satuan',
                        'package_name' => $origin?->package_name,
                        'rack_code' => $item['rack_code'],
                        'expiry_date' => $expiry,
                        'status' => InstrumentStorage::STATUS_TERSIMPAN,
                        'stored_at' => now(),
                    ]);
                    $alreadyStored[] = $stockId;
                }

                if (count(array_intersect($batchStockIds, $alreadyStored)) >= count($batchStockIds)) {
                    PipelineEvent::record(PipelineEvent::STAGE_STERILIZATION, $sterilization->code, PipelineEvent::ACTION_SELESAI, [
                        'note' => 'Seluruh unit tersimpan di gudang steril',
                    ]);
                }

                // Perbarui tahap unit (→ disimpan di rak).
                InstrumentStock::syncStages($batchStockIds);
            });

            return $this->success('Unit berhasil disimpan ke gudang steril.', $this->productionIncomingPayload($sterilization->refresh()));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Map nama paket → gambar SET (katalog) untuk thumbnail grup paket. */
    private function packageImages($names): array
    {
        $names = collect($names)->filter()->unique()->values();
        if ($names->isEmpty()) {
            return [];
        }

        return InstrumentCatalog::whereIn('name', $names)->get()
            ->mapWithKeys(fn ($c) => [$c->name => $c->image_url])
            ->all();
    }

    /** Ringkasan batch produksi siap-simpan (bentuk sama dgn incomingPayload order). */
    private function productionIncomingPayload(Sterilization $batch): array
    {
        $batch->loadMissing([
            'items.instrumentStock.instrument',
            'packagings.washing.production.items',
        ]);

        $production = $batch->packagings->first()?->washing?->production;

        // Asal unit (satuan/paket) diambil dari production_item (via stock id).
        $originByStock = ($production?->items ?? collect())
            ->keyBy('instrument_stock_id');

        // Baris gudang unit batch ini, TERMASUK yang berstatus `keluar` (sudah diambil /
        // didistribusikan). Unit yang pernah masuk gudang tidak boleh muncul lagi sebagai
        // "siap simpan" — kalau hanya baris `tersimpan` yang dihitung, batch yang unitnya
        // sudah dipinjam akan terlihat belum tersimpan dan kembali ke daftar.
        $stored = InstrumentStorage::where('sterilization_id', $batch->id)
            ->get()
            ->keyBy('instrument_stock_id');

        // Gambar SET (katalog paket) per nama paket, untuk thumbnail grup paket.
        $packageImages = $this->packageImages(
            ($production?->items ?? collect())->where('source', 'paket')->pluck('package_name')
        );

        // Hanya unit BERHASIL steril yang jadi isi batch gudang (unit gagal → re-proses,
        // tidak ikut disimpan). result null = batch lama pra per-unit → dianggap berhasil.
        $unitRows = $batch->items
            ->filter(fn ($it) => $it->result !== Sterilization::RESULT_GAGAL)
            ->map(function ($it) use ($stored, $originByStock, $packageImages) {
                $row = $stored->get($it->instrument_stock_id);
                $origin = $originByStock->get($it->instrument_stock_id);
                $stock = $it->instrumentStock;

                return [
                    'id' => $it->instrument_stock_id,
                    'code' => $stock?->code,
                    'instrument' => $stock?->instrument?->name,
                    'image_url' => $stock?->instrument?->image_url,
                    'source' => $origin?->source ?? 'satuan',
                    'package_name' => $origin?->package_name,
                    'package_image' => $origin?->source === 'paket' ? ($packageImages[$origin->package_name] ?? null) : null,
                    'stored' => (bool) $row,
                    'rack_code' => $row?->rack_code,
                ];
            })->values();

        return [
            'id' => $batch->id,                 // id sterilisasi (STR) → dipakai di store_url
            'code' => $batch->code,             // STR-NNN
            'code_transaction' => $production?->code, // PRD-NNN
            'status' => 'steril',
            'source' => 'produksi',
            'store_url' => "/master/sterilization/{$batch->id}/store",
            'borrowed_by' => $production?->displayName() ?? 'Produksi CSSD',
            'room' => null,
            'processed_at' => $batch->sterilized_at ?? $batch->completed_at,
            'expiry_date' => $batch->expiry_date,
            'unit_count' => $unitRows->count(),
            'stored_count' => $unitRows->where('stored', true)->count(),
            'units' => $unitRows,
        ];
    }

    /**
     * Kode batch produksi (PRD-...) terakhir tiap unit — label pada bungkus sterilnya.
     * Dipetakan sekali untuk seluruh baris agar tidak query per unit.
     */
    private function productionCodeMap(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return ProductionItem::with('production')
            ->whereIn('instrument_stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            // Urut id ASC → batch terbaru menimpa yang lama.
            ->mapWithKeys(fn ($it) => [(int) $it->instrument_stock_id => $it->production?->code])
            ->all();
    }

    /** Satu baris inventaris + status kedaluwarsa. */
    private function inventoryRow(InstrumentStorage $s, int $days, array $productionCodes = []): array
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
            'source' => $s->source ?? 'satuan',
            'package_name' => $s->package_name,
            // Kode batch produksi asal unit (PRD-...) — tercetak di bungkus steril.
            'production_code' => $productionCodes[(int) $s->instrument_stock_id] ?? null,
            'unit' => [
                'id' => $s->instrument_stock_id,
                'code' => $s->instrumentStock?->code,
                'instrument' => $s->instrumentStock?->instrument?->name,
                'image_url' => $s->instrumentStock?->instrument?->image_url,
            ],
            'order' => $s->order ? [
                'id' => $s->order->id,
                'code' => $s->order->code,
                'code_transaction' => $s->order->code_transaction,
            ] : null,
            // Kode batch sterilisasi (STR) — untuk pengelompokan inventaris per batch.
            'batch' => $s->sterilization?->code,
        ];
    }
}
