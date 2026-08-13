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
            // Sama persis dengan kolom "Sisa Stok" di daftar — dua angka ini tampil
            // berdampingan di layar yang sama, jadi dasarnya tidak boleh berbeda.
            'available_units' => InstrumentStock::availableStock()->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = Instrument::withCount([
            'stocks',
            // Sisa unit yang benar-benar masih bisa dipakai — lihat scope availableStock:
            // ditentukan dari ada/tidaknya baris relasi + kolom FK/audit, TANPA membaca
            // kolom `status` mana pun. Unit yang sudah masuk produksi CSSD dan belum
            // kembali ke rak dalam keadaan bebas otomatis tidak lagi terhitung.
            'stocks as available_stocks_count' => fn ($q) => $q->availableStock(),
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
     * Aturannya WAJIB sama persis dengan penyusun kandidat distribusi
     * (OrderController::distributionCandidates): scope `sterilePool()` + tanggal
     * kedaluwarsa wajib ada & belum lewat + bungkusnya tidak berisi unit kedaluwarsa.
     * Angka ini adalah janji ke pemesan — begitu ia lebih longgar dari syarat
     * distribusi, form order menjanjikan barang yang nanti ditolak sendiri saat mau
     * dikeluarkan dari gudang.
     *
     * Bedanya dengan daftar Inventaris Gudang Steril: daftar itu TETAP menampilkan
     * baris kedaluwarsa (dengan penanda `can_distribute`/`blocked_reason`) supaya
     * petugas tahu barangnya ada tapi perlu diproses ulang; angka siap-order di sini
     * tidak menghitungnya.
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

        $blocked = InstrumentStorage::blockedPackagingBarcodes();

        return InstrumentStorage::withoutGlobalScopes()
            // Scope BERSAMA dengan penyusun kandidat distribusi & daftar Gudang Steril:
            // belum dihapus, masih `tersimpan` di rak, `order_id` null (belum diklaim
            // order mana pun).
            ->sterilePool()
            // Asal unit (satuan/paket) ada di production_item, bukan di baris gudang.
            ->join('production_item', 'production_item.id', '=', 'instrument_storages.production_item_id')
            ->join('instrument_stocks', 'instrument_stocks.id', '=', 'instrument_storages.instrument_stock_id')
            ->leftJoin('sterilization_items', function ($join) {
                $join->on('sterilization_items.sterilization_id', '=', 'instrument_storages.sterilization_id')
                    ->on('sterilization_items.instrument_stock_id', '=', 'instrument_storages.instrument_stock_id')
                    ->whereNull('sterilization_items.deleted_by');
            })
            ->whereNull('production_item.deleted_by')
            ->whereNull('instrument_stocks.deleted_by')
            ->whereIn('instrument_stocks.instrument_id', $instrumentIds)
            // Unit yang diproduksi sebagai bagian PAKET tidak dihitung sebagai stok satuan.
            ->where('production_item.source', 'satuan')
            // Angka ini adalah JANJI ke pemesan, jadi syaratnya WAJIB sama persis dengan
            // yang dipakai saat distribusi (OrderController::distributionCandidates):
            // wajib bertanggal & belum lewat, dan bungkusnya tidak berisi unit
            // kedaluwarsa. Kalau lebih longgar, form order menjanjikan barang yang
            // nanti ditolak sendiri saat mau dikeluarkan dari gudang.
            ->whereDate('instrument_storages.expiry_date', '>=', now()->toDateString())
            ->when(! empty($blocked), fn ($q) => $q->where(
                fn ($w) => $w->whereNull('sterilization_items.packaging_barcode')
                    ->orWhereNotIn('sterilization_items.packaging_barcode', $blocked)
            ))
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
