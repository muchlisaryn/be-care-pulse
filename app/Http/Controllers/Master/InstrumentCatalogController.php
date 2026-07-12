<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstrumentCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = InstrumentCatalog::withCount('items')
            ->with('items:id,instrument_catalog_id,instrument_id,quantity')
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%"))
            )
            ->orderByDesc('id')
            ->paginate(20);

        // Hitung berapa set paket yang masih bisa dipenuhi dari stok `tersedia`.
        // available_sets = min( floor(stok_tersedia / qty_per_set) ) atas semua isinya.
        $instrumentIds = collect($data->items())
            ->flatMap(fn ($c) => $c->items->pluck('instrument_id'))
            ->filter()->unique()->values();

        $available = InstrumentStock::whereIn('instrument_id', $instrumentIds)
            ->where('status', InstrumentStock::STATUS_TERSEDIA)
            ->selectRaw('instrument_id, count(*) as cnt')
            ->groupBy('instrument_id')
            ->pluck('cnt', 'instrument_id');

        // Stok STERIL per PAKET: unit di gudang steril (status `tersimpan`) yang belum
        // kedaluwarsa DAN diproduksi sebagai paket (`source` = paket). Dikelompokkan per
        // `package_name` — set hanya boleh dipenuhi dari unit yang memang disimpan sebagai
        // paket tsb, bukan dari unit satuan (produksi menentukan bentuknya, bukan order).
        $sterileRows = InstrumentStorage::withoutGlobalScopes()
            ->join('instrument_stocks', 'instrument_stocks.id', '=', 'instrument_storages.instrument_stock_id')
            // LEFT JOIN: stok pipeline produksi disimpan tanpa order (order_id null) —
            // tetap ikut. Baris yang sudah direservasi order-ruangan (room_id terisi)
            // dikecualikan oleh whereNull('order.room_id') di bawah.
            ->leftJoin('order', 'order.id', '=', 'instrument_storages.order_id')
            ->whereNull('instrument_storages.deleted_by')
            ->whereNull('instrument_stocks.deleted_by')
            ->whereNull('order.deleted_by')
            // Hanya stok produksi yang belum dialokasikan ke order (lihat InstrumentController).
            ->whereNull('order.room_id')
            ->whereIn('instrument_stocks.instrument_id', $instrumentIds)
            ->where('instrument_storages.status', InstrumentStorage::STATUS_TERSIMPAN)
            ->where('instrument_storages.source', 'paket')
            ->where(fn ($w) => $w->whereNull('instrument_storages.expiry_date')
                ->orWhereDate('instrument_storages.expiry_date', '>=', now()->toDateString()))
            ->selectRaw('instrument_storages.package_name as package_name, instrument_stocks.instrument_id as instrument_id, count(*) as cnt')
            ->groupBy('instrument_storages.package_name', 'instrument_stocks.instrument_id')
            ->get();

        // cnt per instrument_id, di-key oleh nama paket.
        $sterileByPackage = $sterileRows
            ->groupBy('package_name')
            ->map(fn ($rows) => $rows->pluck('cnt', 'instrument_id'));

        // Berapa set yang bisa dipenuhi: min( floor(stok / qty_per_set) ) atas isinya.
        $setsFrom = fn ($catalog, $counts) => $catalog->items->isEmpty()
            ? 0
            : (int) $catalog->items->min(function ($item) use ($counts) {
                $stock = (int) ($counts[$item->instrument_id] ?? 0);

                return $item->quantity > 0 ? intdiv($stock, $item->quantity) : 0;
            });

        $data->getCollection()->transform(function ($catalog) use ($available, $sterileByPackage, $setsFrom) {
            $catalog->available_sets = $setsFrom($catalog, $available);
            $catalog->available_sterile_sets = $setsFrom($catalog, $sterileByPackage[$catalog->name] ?? collect());
            // Rincian item tidak perlu ikut di list (tersedia di endpoint show).
            $catalog->unsetRelation('items');

            return $catalog;
        });

        return $this->success('Data katalog instrumen berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $catalog = DB::transaction(function () use ($validated) {
                $catalog = InstrumentCatalog::create([
                    'code' => $validated['code'],
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                    'description' => $validated['description'] ?? null,
                ]);

                $this->syncItems($catalog, $validated['items']);

                return $catalog;
            });

            $catalog->load(['items.instrument', 'items.standardCondition'])->loadCount('items');

            return $this->success('Katalog instrumen berhasil ditambahkan.', $catalog, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(InstrumentCatalog $instrumentCatalog): JsonResponse
    {
        $instrumentCatalog->load([
            'items.instrument',
            'items.standardCondition',
        ])->loadCount('items');

        return $this->success('Detail katalog instrumen berhasil diambil.', $instrumentCatalog);
    }

    public function update(Request $request, InstrumentCatalog $instrumentCatalog): JsonResponse
    {
        $validated = $this->validatePayload($request, $instrumentCatalog->id);

        try {
            DB::transaction(function () use ($instrumentCatalog, $validated) {
                $instrumentCatalog->update([
                    'code' => $validated['code'],
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                    'description' => $validated['description'] ?? null,
                ]);

                $this->syncItems($instrumentCatalog, $validated['items']);
            });

            $instrumentCatalog->load(['items.instrument', 'items.standardCondition'])->loadCount('items');

            return $this->success('Katalog instrumen berhasil diperbarui.', $instrumentCatalog);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(InstrumentCatalog $instrumentCatalog): JsonResponse
    {
        try {
            $instrumentCatalog->delete();

            return $this->success('Katalog instrumen berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Unggah / ganti gambar set/paket (opsional). Gambar lama otomatis dihapus.
     */
    public function uploadImage(Request $request, InstrumentCatalog $instrumentCatalog): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        try {
            $dir = public_path('uploads/instrument-catalogs');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->removeImageFile($instrumentCatalog);

            $file = $request->file('image');
            $filename = 'cat-'.$instrumentCatalog->id.'-'.time().'.'.$file->getClientOriginalExtension();
            $file->move($dir, $filename);

            $instrumentCatalog->update(['image' => 'uploads/instrument-catalogs/'.$filename]);

            return $this->success('Gambar set berhasil diunggah.', $instrumentCatalog->fresh());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Hapus gambar set/paket.
     */
    public function deleteImage(InstrumentCatalog $instrumentCatalog): JsonResponse
    {
        try {
            $this->removeImageFile($instrumentCatalog);
            $instrumentCatalog->update(['image' => null]);

            return $this->success('Gambar set berhasil dihapus.', $instrumentCatalog->fresh());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Hapus berkas gambar fisik bila ada. */
    private function removeImageFile(InstrumentCatalog $catalog): void
    {
        if ($catalog->image) {
            $path = public_path($catalog->image);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Validasi payload katalog + rincian.
     * single → tepat 1 rincian, paket → minimal 1 rincian.
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $codeUnique = Rule::unique('instrument_catalogs', 'code')->whereNull('deleted_by');
        if ($ignoreId) {
            $codeUnique->ignore($ignoreId);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', $codeUnique],
            'name' => 'required|string|max:255',
            'type' => 'required|in:single,paket',
            'description' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.instrument_id' => 'required|integer|exists:instruments,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.standard_condition_id' => 'nullable|integer|exists:conditions,id',
            'items.*.note' => 'nullable|string|max:255',
        ]);

        if ($validated['type'] === 'single' && count($validated['items']) !== 1) {
            abort(response()->json([
                'status' => false,
                'message' => 'Tipe single hanya boleh memiliki tepat 1 rincian instrumen.',
                'errors' => ['items' => ['Tipe single hanya boleh memiliki 1 rincian instrumen.']],
            ], 422));
        }

        return $validated;
    }

    /**
     * Hapus rincian lama lalu buat ulang dari payload (full replace).
     */
    private function syncItems(InstrumentCatalog $catalog, array $items): void
    {
        $catalog->items()->forceDelete();

        foreach ($items as $item) {
            $catalog->items()->create([
                'instrument_id' => $item['instrument_id'],
                'quantity' => $item['quantity'],
                'standard_condition_id' => $item['standard_condition_id'] ?? null,
                'note' => $item['note'] ?? null,
            ]);
        }
    }
}
