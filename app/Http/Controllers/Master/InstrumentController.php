<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class InstrumentController extends Controller
{
    public function stats(): JsonResponse
    {
        return $this->success('Statistik instrumen berhasil diambil.', [
            'total_instruments' => Instrument::count(),
            'total_units' => InstrumentStock::count(),
            'available_units' => InstrumentStock::where('status', InstrumentStock::STATUS_TERSEDIA)->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = Instrument::withCount([
            'stocks',
            // Jumlah unit yang berstatus `tersedia` — harus PERSIS sama dgn jumlah unit
            // ber-badge "Tersedia" pada detail stok. Berkurang otomatis saat unit dipinjam
            // (status → dipinjam). Unit yang masih di gudang steril tetap `tersedia` (masih
            // bisa dipinjam) sehingga tetap ikut terhitung — jangan dikecualikan.
            'stocks as available_stocks_count' => fn ($q) => $q->where('status', InstrumentStock::STATUS_TERSEDIA),
        ])
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
            )
            // Urutkan berdasarkan SISA stok (unit `tersedia`), bukan total unit.
            ->when(
                $request->sort === 'stock_asc',
                fn ($q) => $q->orderBy('available_stocks_count', 'asc')
            )
            ->when(
                $request->sort === 'stock_desc',
                fn ($q) => $q->orderBy('available_stocks_count', 'desc')
            )
            ->paginate(20);

        // Lampirkan available_sterile_count: jumlah unit STERIL siap-order (ada di
        // gudang steril, status `tersimpan`, belum kedaluwarsa). Order hanya boleh
        // atas barang yang sudah steril. Dihitung per halaman agar tetap ringan.
        $sterile = $this->sterileCountsByInstrument(collect($data->items())->pluck('id'));
        $data->getCollection()->transform(function ($instrument) use ($sterile) {
            $instrument->available_sterile_count = (int) ($sterile[$instrument->id] ?? 0);

            return $instrument;
        });

        return $this->success('Data instrumen berhasil diambil.', $data);
    }

    /**
     * Jumlah unit STERIL siap-order SATUAN per instrument_id: baris gudang yang belum
     * direservasi order (`order_id` null), belum kedaluwarsa, DAN diproduksi sebagai
     * satuan (`source` = satuan). Unit yang diproduksi & disimpan sebagai PAKET
     * hanya boleh dipinjam sebagai paket utuh — lihat InstrumentCatalogController.
     *
     * `instrument_storages.status` SENGAJA tidak ikut menyaring, sama seperti daftar
     * inventaris Gudang Steril (StorageController@inventory) — angka di sini harus
     * sama persis dengan apa yang tampil di halaman itu. Jangan tambahkan
     * `where('instrument_storages.status', ...)`.
     * Kolom di-kualifikasi + tanpa global scope agar JOIN tidak ambigu pada `deleted_by`.
     *
     * @param  Collection<int,int>  $instrumentIds
     * @return Collection<int,int> cnt di-key oleh instrument_id
     */
    private function sterileCountsByInstrument($instrumentIds)
    {
        if ($instrumentIds->isEmpty()) {
            return collect();
        }

        return InstrumentStorage::withoutGlobalScopes()
            // Asal unit (satuan/paket) ada di production_item, bukan di baris gudang.
            ->join('production_item', 'production_item.id', '=', 'instrument_storages.production_item_id')
            ->join('instrument_stocks', 'instrument_stocks.id', '=', 'instrument_storages.instrument_stock_id')
            ->whereNull('instrument_storages.deleted_by')
            ->whereNull('production_item.deleted_by')
            ->whereNull('instrument_stocks.deleted_by')
            // Hanya baris gudang yang masih jadi POOL produksi: `order_id` null. Begitu
            // sebuah order mereservasinya, `order_id` terisi sehingga otomatis keluar
            // dari hitungan ini — sumber angka yang sama dengan halaman Gudang Steril.
            ->whereNull('instrument_storages.order_id')
            ->whereIn('instrument_stocks.instrument_id', $instrumentIds)
            // Unit yang diproduksi sebagai bagian PAKET tidak dihitung sebagai stok satuan.
            ->where('production_item.source', 'satuan')
            ->where(fn ($w) => $w->whereNull('instrument_storages.expiry_date')
                ->orWhereDate('instrument_storages.expiry_date', '>=', now()->toDateString()))
            ->selectRaw('instrument_stocks.instrument_id as instrument_id, count(*) as cnt')
            ->groupBy('instrument_stocks.instrument_id')
            ->pluck('cnt', 'instrument_id');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('instruments', 'code')->whereNull('deleted_by')],
            'name' => 'required|string|max:255',
        ]);

        try {
            $instrument = Instrument::create($validated);

            return $this->success('Instrumen berhasil ditambahkan.', $instrument, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Instrument $instrument): JsonResponse
    {
        return $this->success('Detail instrumen berhasil diambil.', $instrument);
    }

    public function update(Request $request, Instrument $instrument): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('instruments', 'code')->ignore($instrument->id)->whereNull('deleted_by')],
            'name' => 'required|string|max:255',
        ]);

        try {
            $instrument->update($validated);

            return $this->success('Instrumen berhasil diperbarui.', $instrument);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Instrument $instrument): JsonResponse
    {
        try {
            $instrument->delete();

            return $this->success('Instrumen berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Unggah / ganti gambar instrumen (opsional). Gambar lama otomatis dihapus.
     */
    public function uploadImage(Request $request, Instrument $instrument): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        try {
            $dir = public_path('uploads/instruments');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->removeImageFile($instrument);

            $file = $request->file('image');
            $filename = 'ins-'.$instrument->id.'-'.time().'.'.$file->getClientOriginalExtension();
            $file->move($dir, $filename);

            $instrument->update(['image' => 'uploads/instruments/'.$filename]);

            return $this->success('Gambar instrumen berhasil diunggah.', $instrument->fresh());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Hapus gambar instrumen.
     */
    public function deleteImage(Instrument $instrument): JsonResponse
    {
        try {
            $this->removeImageFile($instrument);
            $instrument->update(['image' => null]);

            return $this->success('Gambar instrumen berhasil dihapus.', $instrument->fresh());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Hapus berkas gambar fisik bila ada. */
    private function removeImageFile(Instrument $instrument): void
    {
        if ($instrument->image) {
            $path = public_path($instrument->image);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
