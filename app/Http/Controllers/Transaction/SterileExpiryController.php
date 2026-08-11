<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStorage;
use App\Models\Sterilization;
use App\Traits\CountsSterileItems;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Alat Kedaluwarsa Steril (halaman /cssd/kedaluwarsa).
 *
 * API TERPISAH dari StorageController (Storage Steril) maupun SterilizationController
 * (CRUD batch sterilisasi) — sengaja tidak digabung supaya perubahan pada halaman ini
 * tidak menyeret dua halaman lain. Yang DIBAGI hanyalah aturan hitungnya, lewat trait
 * `CountsSterileItems`, agar angkanya tidak pernah berbeda dengan Storage Steril.
 *
 * Sumber datanya SAMA dengan Inventaris Gudang di halaman Storage Steril: baris
 * `instrument_storages` ber-`order_id` NULL (stok steril di gudang yang belum
 * direservasi order). Yang ditampilkan hanya baris yang sudah/akan kedaluwarsa dalam
 * ambang `days` hari, diringkas per BATCH sterilisasi.
 *
 * ATURAN JUMLAH UNIT: paket dihitung per SET (satu bungkus/label = 1) dan satuan
 * dihitung per unit (1). Set TIDAK dihitung per instrumen di dalamnya.
 */
class SterileExpiryController extends Controller
{
    use CountsSterileItems;

    /** Ambang hari ke depan bawaan (H-7 steril, sama dengan Storage Steril). */
    private const DEFAULT_DAYS = 7;

    /**
     * Daftar batch steril di gudang yang sudah/akan kedaluwarsa, urut paling mendesak.
     * ?days= ambang hari ke depan (default 7, termasuk yang sudah lewat), ?search=,
     * ?page=.
     */
    public function index(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $today = now()->startOfDay();
        $threshold = $today->copy()->addDays($days)->toDateString();

        // Satu baris hasil = satu batch sterilisasi. Tanggal kedaluwarsa batch =
        // yang PALING DEKAT di antara barisnya (mewakili yang perlu ditindak duluan).
        $batches = $this->baseQuery($threshold)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $this->applySearch($w, $s))
            )
            ->selectRaw('sterilization_id, MIN(expiry_date) as expiry_date')
            ->groupBy('sterilization_id')
            ->orderByRaw('MIN(expiry_date) ASC')
            ->paginate(20);

        $ids = collect($batches->items())->pluck('sterilization_id');

        // Seluruh baris gudang milik batch pada halaman ini (tanpa penyaring pencarian:
        // jumlah unit satu batch harus utuh, bukan hanya yang cocok kata kunci).
        $rows = $this->baseQuery($threshold)
            ->where(function ($q) use ($ids) {
                $q->whereIn('sterilization_id', $ids->filter()->values());
                if ($ids->contains(null)) {
                    $q->orWhereNull('sterilization_id');
                }
            })
            ->with('productionItem:id,source,package_name')
            ->get(['id', 'instrument_stock_id', 'sterilization_id', 'production_item_id', 'expiry_date', 'rack_code'])
            ->groupBy(fn (InstrumentStorage $s) => (int) $s->sterilization_id);

        $meta = Sterilization::whereIn('id', $ids->filter()->values())
            ->get(['id', 'code', 'machine', 'method', 'sterilized_at'])
            ->keyBy('id');

        $barcodes = $this->packagingBarcodeMap($rows->flatten());

        $batches->getCollection()->transform(function ($b) use ($rows, $meta, $barcodes, $days) {
            $id = (int) $b->sterilization_id;

            return $this->batchRow(
                $meta->get($id),
                $id,
                $rows->get($id, collect()),
                $b->expiry_date,
                $barcodes,
                $days
            );
        });

        return $this->success('Data alat kedaluwarsa steril berhasil diambil.', $batches);
    }

    /**
     * Angka kartu statistik halaman: jumlah batch, instrumen yang sudah lewat, dan
     * yang akan kedaluwarsa dalam ambang hari. Dihitung server agar tetap benar walau
     * daftarnya dipecah per halaman, dengan aturan hitung yang sama persis dengan
     * ringkasan Storage Steril (paket per SET, satuan per unit).
     */
    public function summary(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($days);

        $rows = $this->baseQuery($limit->toDateString())
            ->with('productionItem:id,source,package_name')
            ->get(['id', 'instrument_stock_id', 'sterilization_id', 'production_item_id', 'expiry_date', 'rack_code']);

        $barcodes = $this->packagingBarcodeMap($rows);

        return $this->success('Ringkasan alat kedaluwarsa steril berhasil diambil.', [
            // Batch = baris pada daftar; instrumen memakai aturan set = 1, satuan = 1.
            'batches' => $rows->pluck('sterilization_id')->unique()->count(),
            'items' => $this->countAsItems($rows, $barcodes),
            'expired' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date->startOfDay()->lt($today)),
                $barcodes
            ),
            'alert' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date->startOfDay()->gte($today)),
                $barcodes
            ),
        ]);
    }

    /** Ambang hari ke depan dari query string (minimal 0). */
    private function days(Request $request): int
    {
        return max(0, (int) $request->input('days', self::DEFAULT_DAYS));
    }

    /**
     * Basis baris gudang: identik dengan StorageController@inventory (`order_id`
     * NULL, tanpa menyaring status baris gudang maupun status unit) supaya angkanya
     * sebanding dengan halaman Storage Steril — ditambah penyaring kedaluwarsa.
     */
    private function baseQuery(string $threshold): Builder
    {
        return InstrumentStorage::whereNull('order_id')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $threshold);
    }

    /** Pencarian: kode/mesin batch steril, nama & kode instrumen, rak, nomor label. */
    private function applySearch(Builder $q, string $s): Builder
    {
        return $q->where('rack_code', 'like', "%{$s}%")
            ->orWhereHas('sterilization', fn ($b) => $b->where('code', 'like', "%{$s}%")
                ->orWhere('machine', 'like', "%{$s}%"))
            ->orWhereHas('productionItem', fn ($p) => $p->where('name', 'like', "%{$s}%")
                ->orWhere('kode_instrumen', 'like', "%{$s}%")
                ->orWhere('package_name', 'like', "%{$s}%"))
            ->orWhereHas('instrumentStock', fn ($u) => $u->where('code', 'like', "%{$s}%"))
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('sterilization_items')
                ->whereColumn('sterilization_items.instrument_stock_id', 'instrument_storages.instrument_stock_id')
                ->whereColumn('sterilization_items.sterilization_id', 'instrument_storages.sterilization_id')
                ->whereNull('sterilization_items.deleted_by')
                ->where('sterilization_items.packaging_barcode', 'like', "%{$s}%"));
    }

    /**
     * Satu baris daftar = satu batch steril di gudang.
     *
     * `item_count` memakai aturan tampilan: SET dihitung 1 (berapa pun instrumen di
     * dalamnya) dan instrumen satuan dihitung 1. Rinciannya ikut dikirim (`set_count`
     * & `unit_count`) supaya petugas tahu angka itu berasal dari berapa set + berapa
     * satuan; `instrument_count` hanya keterangan tambahan (jumlah fisik instrumen).
     *
     * @param  Collection<int,InstrumentStorage>  $rows
     * @param  array{pairs: array<string,string>, stocks: array<int,string>}  $barcodes
     */
    private function batchRow(?Sterilization $batch, int $id, Collection $rows, $expiry, array $barcodes, int $days): array
    {
        $expiry = $expiry ? Carbon::parse($expiry) : null;
        $daysToExpiry = $expiry
            ? (int) now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false)
            : null;

        [$paket, $satuan] = $rows->partition(
            fn (InstrumentStorage $s) => ($s->productionItem?->source ?? 'satuan') === 'paket'
        );

        return [
            // Batch steril (STR) — id 0 = baris gudang lama tanpa batch.
            'id' => $id,
            'code' => $batch?->code,
            'machine' => $batch?->machine,
            'method' => $batch?->method,
            'sterilized_at' => $batch?->sterilized_at,
            'expiry_date' => $expiry?->toDateString(),
            'days_to_expiry' => $daysToExpiry,
            'expired' => $daysToExpiry !== null && $daysToExpiry < 0,
            'alert' => $daysToExpiry !== null && $daysToExpiry <= $days,
            // Jumlah unit menurut aturan tampilan (set = 1, satuan = 1).
            'item_count' => $this->countAsItems($rows, $barcodes),
            'set_count' => $paket->map(fn ($s) => $this->setKey($s, $barcodes))->unique()->count(),
            'unit_count' => $satuan->count(),
            // Keterangan tambahan: jumlah instrumen fisik (set dijabarkan isinya).
            'instrument_count' => $rows->count(),
            'racks' => $rows->pluck('rack_code')->filter()->unique()->sort()->values()->all(),
        ];
    }
}
