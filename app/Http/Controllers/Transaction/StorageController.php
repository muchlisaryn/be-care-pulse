<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Packaging;
use App\Models\PackagingItem;
use App\Models\PipelineEvent;
use App\Models\ProductionItem;
use App\Models\Sterilization;
use App\Traits\CountsSterileItems;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tahap 5 — Penyimpanan (Storage Management). Menempatkan unit steril ke lokasi
 * rak penyimpanan & memantau masa kedaluwarsa (early-warning).
 */
class StorageController extends Controller
{
    // Aturan hitung jumlah instrumen (paket per SET, satuan per unit) + peta nomor
    // label kemasan — dibagi dengan SterileExpiryController lewat trait agar angka
    // di halaman Storage Steril & Alat Kedaluwarsa Steril selalu sama.
    use CountsSterileItems;

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
            // items → nomor label kemasan (packaging_barcode) tiap unit.
            'sterilizations.items',
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

        // Baris batch produksi tiap unit — dipakai sebagai identitas baris gudang
        // (nama, asal & nama paket dibaca dari sini, tidak lagi disalin).
        $prodItems = $this->productionItemMap($orderStockIds);

        $batch = $order->sterilizations()->where('status', 'selesai')->latest()->first();
        $expiry = $batch?->expiry_date;

        // Unit yang sudah tersimpan sebelumnya (hindari duplikat).
        $alreadyStored = InstrumentStorage::where('order_id', $order->id)
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->pluck('instrument_stock_id')->all();

        try {
            DB::transaction(function () use ($validated, $order, $orderStockIds, $alreadyStored, $batch, $expiry, $prodItems) {
                foreach ($validated['items'] as $item) {
                    $stockId = (int) $item['instrument_stock_id'];

                    // Abaikan unit yang bukan milik order atau sudah tersimpan.
                    if (! in_array($stockId, $orderStockIds, true) || in_array($stockId, $alreadyStored, true)) {
                        continue;
                    }

                    $prod = $prodItems[$stockId] ?? null;
                    if (! $prod) {
                        throw new \RuntimeException(
                            "Unit #{$stockId} belum punya baris batch produksi, tidak bisa disimpan ke gudang steril."
                        );
                    }

                    InstrumentStorage::create([
                        'order_id' => $order->id,
                        'sterilization_id' => $batch?->id,
                        'production_item_id' => $prod->id,
                        'instrument_stock_id' => $prod->instrument_stock_id,
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
                'sterilizations.items',
            ]);

            return $this->success('Unit berhasil disimpan ke gudang steril.', $this->incomingPayload($order));
        } catch (\RuntimeException $e) {
            // Unit tanpa asal produksi — validasi bisnis, bukan error server.
            return $this->error($e->getMessage(), 422);
        } catch (UniqueConstraintViolationException $e) {
            // Sebuah constraint unik `instrument_storages` menahan penyimpanan:
            // unit itu sudah punya baris rak. Terjadi bila dua petugas menyimpan
            // batch yang sama bersamaan — pemeriksaan status di atas baca-lalu-
            // tulis, jadi keduanya bisa lolos dan database yang menahan yang
            // kedua. Diterjemahkan ke pesan yang bisa ditindaklanjuti daripada
            // galat SQL mentah.
            //
            // Repo ini sendiri tidak memasang constraint tsb (rencana index unik
            // `active_stock_id` dibatalkan; aturannya dijaga di kode — lihat
            // InstrumentStorage::heldInRackStockIds()). Penangkap ini ditinggal
            // sebagai jaring pengaman untuk database yang memasangnya sendiri.
            return $this->error(
                'Sebagian unit sudah tersimpan di gudang steril oleh proses lain. '
                .'Muat ulang halaman untuk melihat keadaan terbaru.',
                422
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Inventaris real-time gudang steril: unit yang sedang tersimpan + lokasi rak +
     * status kedaluwarsa (alert merah bila ≤ ambang hari atau sudah lewat).
     * ?days= ambang early-warning (default 7).
     *
     * SATU-SATUNYA penyaring baris adalah `instrument_storages.order_id` yang harus
     * NULL — yaitu stok steril pool produksi yang belum direservasi order manapun.
     * Begitu order diterima, `OrderController@acceptDistribution` memindahkan
     * kepemilikan baris gudang ke order tersebut (order_id terisi) dan barisnya keluar
     * dari daftar ini.
     *
     * Status baris gudang (`tersimpan`/`keluar`) dan kondisi unitnya (`tersedia`/
     * `dipinjam`/`sterilisasi`) SENGAJA tidak ikut menyaring — jangan tambahkan
     * `where('status', ...)` di sini.
     */
    public function inventory(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));

        $rows = InstrumentStorage::with([
            'instrumentStock.instrument',
            'productionItem.production',
            'order',
            'sterilization',
        ])
            // Scope BERSAMA dengan penyusun kandidat distribusi
            // (OrderController::distributionCandidates) — daftar ini dan yang bisa
            // didistribusikan wajib berangkat dari baris yang sama. Termasuk
            // `status = tersimpan`: baris unit yang sudah ditarik kembali ke produksi
            // dulu tetap terpajang di sini sebagai stok, padahal fisiknya bukan lagi
            // isi rak.
            ->sterilePool()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('rack_code', 'like', "%{$s}%")
                    ->orWhereHas('instrumentStock', fn ($u) => $u->where('code', 'like', "%{$s}%"))
                    ->orWhereHas('instrumentStock.instrument', fn ($i) => $i->where('name', 'like', "%{$s}%"))
                    // Nama & kode yang TAMPIL berasal dari production_item, ikut dicari.
                    ->orWhereHas('productionItem', fn ($p) => $p->where('name', 'like', "%{$s}%")
                        ->orWhere('kode_instrumen', 'like', "%{$s}%"))
                    // Nomor label kemasan yang tercetak di bungkus sterilnya.
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('sterilization_items')
                        ->whereColumn('sterilization_items.instrument_stock_id', 'instrument_storages.instrument_stock_id')
                        ->whereColumn('sterilization_items.sterilization_id', 'instrument_storages.sterilization_id')
                        ->whereNull('sterilization_items.deleted_by')
                        ->where('sterilization_items.packaging_barcode', 'like', "%{$s}%"))
                    ->orWhereHas('order', fn ($o) => $o->where('code', 'like', "%{$s}%")
                        ->orWhere('code_transaction', 'like', "%{$s}%")))
            )
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(20);

        $barcodes = $this->packagingBarcodeMap($rows->getCollection());
        // Label yang seluruh isinya tidak layak distribusi — aturan yang sama persis
        // dipakai penyusun kandidat distribusi, jadi penandanya di layar tidak bisa
        // berbeda dari kenyataan saat tombol Distribusikan ditekan.
        $blocked = InstrumentStorage::blockedPackagingBarcodes();

        $rows->getCollection()->transform(
            fn (InstrumentStorage $s) => $this->inventoryRow($s, $days, $barcodes, $blocked)
        );

        return $this->success('Inventaris gudang steril berhasil diambil.', $rows);
    }

    /**
     * Ringkasan angka gudang steril: total instrumen tersimpan, mendekati kedaluwarsa
     * (≤ ambang hari, belum lewat) & sudah kedaluwarsa. Dipakai kartu statistik FE
     * agar tetap akurat walau daftar inventarisnya dimuat bertahap (lazy load).
     * ?days= ambang early-warning (default 7).
     *
     * ATURAN HITUNG: paket dihitung per SET (satu bungkus/label = 1), instrumen
     * `satuan` dihitung per unit — jadi paket berisi 5 instrumen tetap bernilai 1.
     * Karena itu angkanya dihitung di PHP (butuh nomor label kemasan), bukan `count()`.
     *
     * Angka di sini WAJIB sama dengan penjumlahan kepala grup rak di halaman
     * Inventaris Gudang. Karena itu baris yang dihitung memakai penyaring yang sama
     * persis dengan inventory(): scope `sterilePool()`. Jangan tambahkan syarat
     * apa pun di salah satu tempat saja — begitu keduanya berangkat dari baris yang
     * berbeda, kartu statistik dan daftarnya langsung tidak cocok lagi.
     */
    public function summary(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));
        $today = now()->startOfDay();

        // Basis identik dengan inventory(): scope sterilePool() yang sama.
        $rows = InstrumentStorage::sterilePool()
            ->with('productionItem:id,source,package_name')
            ->get([
                'id', 'instrument_stock_id', 'sterilization_id', 'production_item_id',
                'expiry_date', 'rack_code',
            ]);

        $barcodes = $this->packagingBarcodeMap($rows);
        $limit = $today->copy()->addDays($days);

        return $this->success('Ringkasan gudang steril berhasil diambil.', [
            'total' => $this->countAsItems($rows, $barcodes),
            'alert' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date
                    && $s->expiry_date->startOfDay()->gte($today)
                    && $s->expiry_date->startOfDay()->lte($limit)),
                $barcodes
            ),
            'expired' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date && $s->expiry_date->startOfDay()->lt($today)),
                $barcodes
            ),
        ]);
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

        // Nama instrumen diambil dari production_item (snapshot batch produksi);
        // relasi instrumen hanya jadi cadangan bila unit belum pernah diproduksi.
        $prodItems = $this->productionItemMap($units->pluck('instrument_stock_id')->all());

        // Nomor label kemasan per unit — dibawa baris sterilisasi batch terakhir order ini.
        $barcodeByStock = $order->relationLoaded('sterilizations')
            ? optional($order->sterilizations->first())?->items->pluck('packaging_barcode', 'instrument_stock_id')
                ?? collect()
            : collect();

        $unitRows = $units->map(function ($it) use ($storedByStock, $packageImages, $prodItems, $barcodeByStock) {
            $stored = $storedByStock->get($it->instrument_stock_id);
            $prod = $prodItems[(int) $it->instrument_stock_id] ?? null;

            return [
                'id' => $it->instrument_stock_id,
                'code' => $prod?->kode_instrumen ?? $it->instrumentStock?->code,
                'instrument' => $prod?->name ?? $it->instrumentStock?->instrument?->name,
                // Nomor label kemasan yang tercetak di bungkus sterilnya.
                'barcode_no' => $barcodeByStock[$it->instrument_stock_id] ?? null,
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

        // Baris batch produksi tiap unit — jadi identitas baris gudang (nama, asal &
        // nama paket dibaca dari sini, tidak lagi disalin ke kolom gudang).
        $originByStock = $this->productionOriginMap(
            $sterilization,
            $this->batchPackagings($sterilization)
        );

        // Unit yang sudah pernah disimpan (hindari duplikat). Termasuk baris `keluar`:
        // unit yang sudah didistribusikan tidak boleh dibuatkan baris gudang baru.
        $alreadyStored = InstrumentStorage::where('sterilization_id', $sterilization->id)
            ->pluck('instrument_stock_id')->all();

        try {
            DB::transaction(function () use ($validated, $sterilization, $batchStockIds, $alreadyStored, $expiry, $originByStock) {
                // Hanya unit yang MASIH `tersedia` boleh masuk gudang. Unit yang sejak
                // divalidasi sudah ditarik lagi ke produksi ulang (status `sterilisasi`)
                // atau sudah dipinjam TIDAK disimpan — mencegah baris gudang "hantu"
                // (tersimpan tapi status ≠ tersedia) yang tak bisa dialokasikan.
                $tersediaStockIds = InstrumentStock::withoutGlobalScopes()
                    ->whereIn('id', $batchStockIds)
                    ->where('status', InstrumentStock::STATUS_TERSEDIA)
                    ->pluck('id')
                    ->all();

                foreach ($validated['items'] as $item) {
                    $stockId = (int) $item['instrument_stock_id'];

                    if (! in_array($stockId, $batchStockIds, true)
                        || in_array($stockId, $alreadyStored, true)
                        || ! in_array($stockId, $tersediaStockIds, true)) {
                        continue;
                    }

                    $origin = $originByStock[$stockId] ?? null;
                    if (! $origin) {
                        throw new \RuntimeException(
                            "Unit #{$stockId} belum punya baris batch produksi, tidak bisa disimpan ke gudang steril."
                        );
                    }

                    InstrumentStorage::create([
                        'order_id' => null,
                        'sterilization_id' => $sterilization->id,
                        'production_item_id' => $origin->id,
                        'instrument_stock_id' => $origin->instrument_stock_id,
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
        } catch (\RuntimeException $e) {
            // Unit tanpa asal produksi — validasi bisnis, bukan error server.
            return $this->error($e->getMessage(), 422);
        } catch (UniqueConstraintViolationException $e) {
            // Sebuah constraint unik `instrument_storages` menahan penyimpanan:
            // unit itu sudah punya baris rak. Terjadi bila dua petugas menyimpan
            // batch yang sama bersamaan — pemeriksaan status di atas baca-lalu-
            // tulis, jadi keduanya bisa lolos dan database yang menahan yang
            // kedua. Diterjemahkan ke pesan yang bisa ditindaklanjuti daripada
            // galat SQL mentah.
            //
            // Repo ini sendiri tidak memasang constraint tsb (rencana index unik
            // `active_stock_id` dibatalkan; aturannya dijaga di kode — lihat
            // InstrumentStorage::heldInRackStockIds()). Penangkap ini ditinggal
            // sebagai jaring pengaman untuk database yang memasangnya sendiri.
            return $this->error(
                'Sebagian unit sudah tersimpan di gudang steril oleh proses lain. '
                .'Muat ulang halaman untuk melihat keadaan terbaru.',
                422
            );
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
        $batch->loadMissing(['items.instrumentStock.instrument']);

        // Satu batch steril bisa memuat unit dari BEBERAPA batch produksi sekaligus —
        // tiap bungkus punya jalur produksi/pencucian/pengemasannya sendiri dan baru
        // bertemu di mesin sterilisator. Karena itu peta asal unit dirangkai dari
        // SELURUH packaging batch ini, sama seperti storeProduction().
        //
        // Dulu hanya packaging PERTAMA yang dibaca, sehingga unit dari produksi lain
        // tidak ketemu asalnya lalu jatuh ke nilai bawaan `satuan` tanpa nama paket:
        // satu SET utuh tampil sebagai instrumen satuan lepas di daftar "Perlu Disimpan".
        $packagings = $this->batchPackagings($batch);
        $production = $packagings->map(fn ($p) => $p->washing?->production)->filter()->first();

        // Asal unit (satuan/paket) diambil dari production_item (via stock id).
        $originByStock = $this->productionOriginMap($batch, $packagings);
        $originItems = collect($originByStock);

        // Baris gudang unit batch ini, TERMASUK yang berstatus `keluar` (sudah diambil /
        // didistribusikan). Unit yang pernah masuk gudang tidak boleh muncul lagi sebagai
        // "siap simpan" — kalau hanya baris `tersimpan` yang dihitung, batch yang unitnya
        // sudah dipinjam akan terlihat belum tersimpan dan kembali ke daftar.
        $stored = InstrumentStorage::where('sterilization_id', $batch->id)
            ->get()
            ->keyBy('instrument_stock_id');

        // Gambar SET (katalog paket) per nama paket, untuk thumbnail grup paket.
        $packageImages = $this->packageImages(
            $originItems->where('source', 'paket')->pluck('package_name')
        );

        // Hanya unit BERHASIL steril yang jadi isi batch gudang (unit gagal → re-proses,
        // tidak ikut disimpan). result null = batch lama pra per-unit → dianggap berhasil.
        $unitRows = $batch->items
            ->filter(fn ($it) => $it->result !== Sterilization::RESULT_GAGAL)
            ->map(function ($it) use ($stored, $originByStock, $packageImages) {
                $row = $stored->get($it->instrument_stock_id);
                $origin = $originByStock[(int) $it->instrument_stock_id] ?? null;
                $stock = $it->instrumentStock;

                // Nama, kode & foto unit diambil dari production_item (snapshot batch);
                // relasi instrumen hanya cadangan bila baris produksinya tak ada.
                return [
                    'id' => $it->instrument_stock_id,
                    'code' => $origin?->kode_instrumen ?? $stock?->code,
                    'instrument' => $origin?->name ?? $stock?->instrument?->name,
                    // Nomor label kemasan yang tercetak di bungkus sterilnya — satu
                    // label = satu bungkus, jadi seluruh unit satu set berbagi nomor.
                    'barcode_no' => $it->packaging_barcode,
                    // Baris `paket` menyimpan foto KATALOG di kolom image — untuk foto
                    // instrumen per unit tetap pakai foto master.
                    'image_url' => $origin?->source === 'paket'
                        ? $stock?->instrument?->image_url
                        : ($origin?->image_url ?? $stock?->instrument?->image_url),
                    'source' => $origin?->source ?? 'satuan',
                    'package_name' => $origin?->package_name,
                    'package_image' => $origin?->source === 'paket'
                        ? ($origin->image_url ?? $packageImages[$origin->package_name] ?? null)
                        : null,
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
     * PKG asal sebuah batch STR, ditelusuri dari NOMOR LABEL unitnya
     * (`sterilization_items.packaging_barcode`) — **bukan** dari relasi
     * `packaging.sterilization_id`.
     *
     * Satuan pemilihan di tahap sterilisasi adalah LABEL, bukan PKG, jadi satu PKG
     * bisa dipecah ke beberapa batch steril. Kolom `packaging.sterilization_id`
     * hanya menyimpan SATU batch — yang TERAKHIR menimpanya — sehingga batch yang
     * lebih lama mendapati `packagings`-nya kosong, seluruh unitnya kehilangan asal
     * produksi, dan penyimpanan ke gudang gagal dengan "belum punya baris batch
     * produksi" padahal batch produksinya ada.
     *
     * Cara telusur yang sama dipakai SterilizationPipelineController::batchPackagings();
     * jangan kembalikan salah satunya ke `packaging.sterilization_id`.
     *
     * @return Collection<int,Packaging>
     */
    private function batchPackagings(Sterilization $batch): Collection
    {
        $batch->loadMissing('items');

        $barcodes = $batch->items->pluck('packaging_barcode')->filter()->unique();

        if ($barcodes->isNotEmpty()) {
            $ids = PackagingItem::whereIn('barcode_no', $barcodes->all())
                ->pluck('packaging_id')
                ->filter()
                ->unique();

            if ($ids->isNotEmpty()) {
                return Packaging::with('washing.production.items')
                    ->whereIn('id', $ids->all())
                    ->get();
            }
        }

        // Batch lama: itemnya belum menyimpan `packaging_barcode`.
        $batch->loadMissing('packagings.washing.production.items');

        return $batch->packagings;
    }

    /**
     * Baris `production_item` asal tiap unit sebuah batch STR, dikunci per
     * instrument_stock_id — identitas baris gudang (nama, kode, asal & nama paket
     * dibaca dari sini).
     *
     * Sumber utamanya rantai PKG → washing → produksi milik batch ini, supaya yang
     * terbaca adalah SNAPSHOT batch produksi yang benar-benar dilewati unitnya.
     * Unit yang tak terjangkau rantai itu — unit re-proses lepas yang masuk batch
     * tanpa PKG, atau rantai washing/produksinya terputus — jatuh ke
     * `production_item` TERAKHIR unit tersebut, sumber yang sama persis dipakai
     * jalur order di store(). Tanpa cadangan ini satu unit yang tak terpetakan
     * menggagalkan penyimpanan SELURUH batch.
     *
     * @param  Collection<int,Packaging>  $packagings
     * @return array<int,ProductionItem>
     */
    private function productionOriginMap(Sterilization $batch, Collection $packagings): array
    {
        $origin = [];
        foreach ($packagings as $pkg) {
            foreach ($pkg->washing?->production?->items ?? [] as $item) {
                $origin[(int) $item->instrument_stock_id] = $item;
            }
        }

        $batch->loadMissing('items');
        $missing = $batch->items
            ->pluck('instrument_stock_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => isset($origin[$id]))
            ->all();

        return $missing ? $origin + $this->productionItemMap($missing) : $origin;
    }

    /**
     * `production_item` TERAKHIR tiap unit — sumber tunggal nama instrumen, kode
     * unit & foto (snapshot batch produksi), plus kode batch produksi (PRD-...)
     * yang tercetak di bungkus sterilnya. Dipetakan sekali untuk seluruh baris
     * agar tidak query per unit.
     *
     * @return array<int,ProductionItem>
     */
    private function productionItemMap(array $stockIds): array
    {
        $stockIds = array_values(array_unique(array_filter($stockIds)));
        if (empty($stockIds)) {
            return [];
        }

        return ProductionItem::with('production')
            ->whereIn('instrument_stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            // Urut id ASC → batch terbaru menimpa yang lama.
            ->mapWithKeys(fn ($it) => [(int) $it->instrument_stock_id => $it])
            ->all();
    }

    /**
     * Satu baris inventaris + status kedaluwarsa. Nama, kode, asal & nama paket
     * dibaca dari relasi `productionItem` (baris batch produksi asal unit);
     * `$barcodes` = peta nomor label kemasan dari packagingBarcodeMap().
     */
    private function inventoryRow(InstrumentStorage $s, int $days, array $barcodes = [], array $blocked = []): array
    {
        $prod = $s->productionItem;
        $barcode = $barcodes['pairs'][$s->sterilization_id.'|'.$s->instrument_stock_id]
            ?? $barcodes['stocks'][(int) $s->instrument_stock_id]
            ?? null;

        $daysToExpiry = null;
        $alert = false;
        $expired = false;

        if ($s->expiry_date) {
            $daysToExpiry = (int) now()->startOfDay()->diffInDays($s->expiry_date->copy()->startOfDay(), false);
            $expired = $daysToExpiry < 0;
            $alert = $daysToExpiry <= $days; // termasuk yang sudah lewat
        }

        // Kelayakan distribusi ditampilkan APA ADANYA di daftar — dulu baris seperti ini
        // terlihat sebagai stok biasa lalu ditolak diam-diam saat distribusi dengan
        // keterangan stok kosong. Urutan pemeriksaannya sama dengan urutan syarat di
        // OrderController::distributionCandidates().
        $blockedReason = match (true) {
            $s->expiry_date === null => 'Tanpa tanggal kedaluwarsa',
            $expired => 'Kedaluwarsa',
            $barcode !== null && in_array($barcode, $blocked, true) => 'Sebungkus dengan unit kedaluwarsa',
            default => null,
        };

        return [
            // Bisa didistribusikan atau tidak, beserta alasannya bila tidak.
            'can_distribute' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
            'id' => $s->id,
            'rack_code' => $s->rack_code,
            'stored_at' => $s->stored_at,
            'expiry_date' => $s->expiry_date,
            'days_to_expiry' => $daysToExpiry,
            'alert' => $alert,
            'expired' => $expired,
            // Asal & nama paket bersumber dari production_item (snapshot batch produksi).
            'source' => $prod?->source ?? 'satuan',
            'package_name' => $prod?->package_name,
            // Nomor label kemasan yang tercetak di bungkus sterilnya — satu label =
            // satu bungkus, jadi seluruh unit satu set berbagi nomor yang sama.
            'barcode_no' => $barcode,
            // Kode batch produksi asal unit (PRD-...).
            'production_code' => $prod?->production?->code,
            // Nama & kode unit bersumber dari production_item (snapshot batch produksi);
            // relasi master instrumen hanya dipakai untuk foto & sebagai cadangan.
            'unit' => [
                'id' => $s->instrument_stock_id,
                'code' => $prod?->kode_instrumen ?? $s->instrumentStock?->code,
                'instrument' => $prod?->name ?? $s->instrumentStock?->instrument?->name,
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
