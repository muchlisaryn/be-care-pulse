<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\Sterilization;
use App\Traits\CountsSterileItems;
use App\Traits\ReprocessesPackaging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    use CountsSterileItems, ReprocessesPackaging;

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

    /**
     * Rincian isi satu batch steril di gudang, DIPECAH PER LABEL KEMASAN — dasar
     * pilihan pada aksi "Packaging Ulang".
     *
     * Satu baris hasil = satu bungkus fisik. Sterilitas melekat pada BUNGKUS, bukan
     * pada instrumen (lihat InstrumentStorage::blockedPackagingBarcodes), jadi
     * petugas memilih per label, bukan per instrumen. Instrumen satuan jadi barisnya
     * sendiri — aturan pengelompokan yang sama persis dengan `countAsItems()`,
     * supaya jumlah baris di sini cocok dengan `item_count` di daftar batch.
     *
     * `?days=` memakai ambang yang sama dengan daftar agar isinya tidak berbeda
     * dengan angka pada baris yang diklik.
     */
    public function units(Request $request, int $sterilization): JsonResponse
    {
        $days = $this->days($request);
        $today = now()->startOfDay();
        $threshold = $today->copy()->addDays($days)->toDateString();

        $rows = $this->baseQuery($threshold)
            ->when(
                $sterilization > 0,
                fn ($q) => $q->where('sterilization_id', $sterilization),
                fn ($q) => $q->whereNull('sterilization_id')
            )
            ->with(['productionItem:id,source,package_name,name,kode_instrumen', 'instrumentStock:id,code'])
            ->get();

        $barcodes = $this->packagingBarcodeMap($rows);

        $groups = $rows
            ->groupBy(fn (InstrumentStorage $s) => $this->groupKey($s, $barcodes))
            ->map(fn (Collection $items, string $key) => $this->labelRow($key, $items, $barcodes, $today))
            ->values()
            // Yang sudah kedaluwarsa didahulukan — itu yang bisa ditindak.
            ->sortBy([['expired', 'desc'], ['expiry_date', 'asc']])
            ->values();

        return $this->success('Rincian isi batch steril berhasil diambil.', [
            'sterilization_id' => $sterilization,
            'labels' => $groups,
        ]);
    }

    /**
     * PACKAGING ULANG — tarik label kedaluwarsa dari rak dan buka ronde pengemasan
     * baru (record RPK) berisi unit-unitnya.
     *
     * Baris raknya TIDAK dihapus, hanya di-void (`disabled`/`disabled_at`) supaya
     * riwayat rak, batch steril, dan nomor label lamanya tetap terbaca. Efek void
     * itu berlapis dan sengaja: unit hilang dari Gudang Steril, tidak bisa dipinjam
     * (`sterilePool()`), tidak terhitung stok bebas (`scopeAvailableStock`), dan
     * badge tahapnya berubah jadi Pengemasan (`computeStages`).
     *
     * Pilihan petugas DIPERLUAS ke seluruh isi label yang tersentuh: begitu satu
     * unit sebuah set ditarik, bungkusnya sudah dibuka, jadi sisa isinya tidak
     * boleh ditinggal di rak sebagai set tak lengkap yang tak bisa didistribusikan
     * dan tak bisa ditarik.
     */
    public function repackage(Request $request, int $sterilization): JsonResponse
    {
        $validated = $request->validate([
            'storage_ids' => 'required|array|min:1',
            'storage_ids.*' => 'required|integer',
        ]);

        // Baris gudang lama tanpa batch steril tidak punya jejak ke PKG asalnya,
        // jadi ronde pengemasan barunya tidak bisa dirangkai. Ditolak terang-terangan
        // daripada membuat RPK yatim yang memutus riwayat unit.
        if ($sterilization <= 0) {
            return $this->error(
                'Baris gudang ini tidak terhubung ke batch sterilisasi mana pun (data lama), '
                .'sehingga kemasan asalnya tidak bisa dilacak untuk dikemas ulang.',
                422
            );
        }

        $batch = Sterilization::find($sterilization);

        if (! $batch) {
            return $this->error('Batch sterilisasi tidak ditemukan.', 404);
        }

        try {
            $result = DB::transaction(function () use ($batch, $validated) {
                // SELURUH isi batch yang masih di rak diambil sekali & DIKUNCI: dua
                // petugas yang menekan tombol bersamaan tidak boleh sama-sama membuka
                // ronde RPK untuk unit yang sama. Yang dipilih petugas disaring dari
                // koleksi ini — bukan lewat query kedua — supaya relasi productionItem
                // yang dipakai groupKey() sudah termuat dan tidak memicu query per baris.
                $all = InstrumentStorage::stillInRack()
                    ->whereNull('order_id')
                    ->where('sterilization_id', $batch->id)
                    ->with('productionItem:id,source,package_name')
                    ->lockForUpdate()
                    ->get();

                $picked = $all->whereIn('id', $validated['storage_ids']);

                if ($picked->isEmpty()) {
                    throw new \RuntimeException(
                        'Tidak ada unit yang bisa dikemas ulang — kemungkinan sudah diproses petugas lain. '
                        .'Muat ulang daftarnya.'
                    );
                }

                $today = now()->startOfDay();
                $belum = $picked->filter(fn ($s) => $s->expiry_date === null
                    || $s->expiry_date->startOfDay()->gte($today));

                if ($belum->isNotEmpty()) {
                    throw new \RuntimeException(
                        'Hanya unit yang SUDAH kedaluwarsa yang bisa dikemas ulang; '
                        .$belum->count().' unit terpilih masih berlaku.'
                    );
                }

                // Perluas ke SATU LABEL UTUH: begitu satu unit sebuah set ditarik,
                // bungkusnya sudah dibuka, jadi sisa isinya tidak boleh ditinggal di
                // rak sebagai set tak lengkap yang tak bisa didistribusikan.
                $barcodes = $this->packagingBarcodeMap($all);
                $keys = $picked->map(fn ($s) => $this->groupKey($s, $barcodes))->unique();
                $rows = $all->filter(fn ($s) => $keys->contains($this->groupKey($s, $barcodes)));

                $stockIds = $rows->pluck('instrument_stock_id')->filter()
                    ->map(fn ($v) => (int) $v)->unique()->values();

                // Baris gudang tanpa unit fisik (data rusak) tidak punya apa pun untuk
                // dikemas ulang — jangan sampai baris raknya di-void tanpa ronde baru.
                if ($stockIds->isEmpty()) {
                    throw new \RuntimeException(
                        'Baris gudang terpilih tidak terhubung ke unit instrumen mana pun, '
                        .'jadi tidak ada yang bisa dikemas ulang.'
                    );
                }

                // Baris rak di-void — barangnya diangkat untuk dikemas ulang.
                InstrumentStorage::whereIn('id', $rows->pluck('id')->all())->update([
                    'status' => InstrumentStorage::STATUS_KELUAR,
                    'removed_at' => now(),
                    'disabled' => true,
                    'disabled_at' => now(),
                    'updated_by' => auth()->user()?->name,
                ]);

                // Ronde pengemasan baru (RPK) — mekanisme yang sama dengan unit gagal steril.
                $round = $this->openReprocessRound(
                    $batch,
                    $stockIds->all(),
                    'unit kedaluwarsa '.$batch->code.' ditarik dari rak'
                );

                // Unit yang tidak ketemu di PKG mana pun akan lenyap dari pipeline:
                // baris raknya sudah di-void tapi tidak ada ronde baru yang memuatnya.
                // Seluruh transaksi dibatalkan daripada meninggalkan unit menggantung.
                $tertinggal = $stockIds->diff($round['stock_ids']);

                if ($tertinggal->isNotEmpty()) {
                    throw new \RuntimeException(
                        'Kemasan asal '.$tertinggal->count().' unit tidak ditemukan pada batch '
                        .$batch->code.', jadi ronde pengemasan barunya tidak bisa dibuat. '
                        .'Tidak ada perubahan yang disimpan.'
                    );
                }

                // Unit kembali masuk pipeline — status yang sama dengan jalur unit gagal steril.
                InstrumentStock::transitionMany($stockIds->all(), InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'sterilization',
                    'reference' => $batch->code,
                    'note' => 'Kedaluwarsa — ditarik dari rak untuk dikemas ulang',
                ]);
                // Badge tahap unit (kolom `stage`) ikut disegarkan DI DALAM
                // transitionMany(). Pada titik ini baris raknya sudah di-void & ronde
                // RPK-nya sudah ada, jadi computeStages() membaca keadaan yang benar:
                // Pengemasan, bukan Kedaluwarsa. Urutan pemanggilan itu penting —
                // jangan pindahkan blok ini ke atas.

                return [
                    'labels' => $keys->count(),
                    'units' => $stockIds->count(),
                    'packagings' => collect($round['packagings'])->map(fn ($p) => $p->full_code)->values()->all(),
                ];
            });

            return $this->success(
                "{$result['units']} unit ({$result['labels']} label) ditarik dari rak & masuk antrean Packaging: "
                .implode(', ', $result['packagings']).'.',
                $result
            );
        } catch (\RuntimeException $e) {
            // Validasi bisnis (sudah diproses / belum kedaluwarsa / kemasan asal tak
            // terlacak) — bukan error server.
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Kunci pengelompokan satu BUNGKUS untuk daftar pilihan & perluasan pilihan.
     *
     * Aturannya sengaja sama persis dengan `countAsItems()`: baris `paket`
     * dikelompokkan per set (rak + batch steril + nomor label), baris `satuan`
     * berdiri sendiri per unit. Begitu keduanya berbeda, jumlah baris yang dipilih
     * petugas tidak akan cocok dengan angka `item_count` di daftar batch.
     *
     * @param  array{pairs: array<string,string>, stocks: array<int,string>}  $barcodes
     */
    private function groupKey(InstrumentStorage $s, array $barcodes): string
    {
        return ($s->productionItem?->source ?? 'satuan') === 'paket'
            ? $this->setKey($s, $barcodes)
            : 'satuan#'.$s->id;
    }

    /**
     * Satu baris pilihan = satu bungkus. `storage_ids` yang dikirim balik ke aksi
     * `repackage()` adalah seluruh isi bungkus itu.
     *
     * @param  Collection<int,InstrumentStorage>  $items
     * @param  array{pairs: array<string,string>, stocks: array<int,string>}  $barcodes
     */
    private function labelRow(string $key, Collection $items, array $barcodes, Carbon $today): array
    {
        $first = $items->first();
        $isPaket = ($first->productionItem?->source ?? 'satuan') === 'paket';
        $expiry = $first->expiry_date;
        $expired = $expiry === null || $expiry->startOfDay()->lt($today);

        return [
            'key' => $key,
            'barcode_no' => $this->barcodeOf($first, $barcodes),
            'type' => $isPaket ? 'paket' : 'satuan',
            // Nama dari SNAPSHOT production_item: nama PAKET untuk set, nama
            // INSTRUMEN untuk satuan. Relasi live hanya cadangan.
            'name' => $isPaket
                ? ($first->productionItem?->package_name ?? 'Paket')
                : ($first->productionItem?->name ?? 'Instrumen'),
            'rack_code' => $first->rack_code,
            'expiry_date' => $expiry?->toDateString(),
            'days_to_expiry' => $expiry
                ? (int) $today->diffInDays($expiry->copy()->startOfDay(), false)
                : null,
            'expired' => $expired,
            'unit_count' => $items->count(),
            'storage_ids' => $items->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
            'units' => $items->map(fn (InstrumentStorage $s) => [
                'storage_id' => (int) $s->id,
                'stock_id' => (int) $s->instrument_stock_id,
                'code' => $s->productionItem?->kode_instrumen ?? $s->instrumentStock?->code,
                'name' => $s->productionItem?->name ?? 'Instrumen',
            ])->values()->all(),
        ];
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
     *
     * `stillInRack()` wajib ada di sini. Dulu penyaringnya hanya `order_id` NULL,
     * sehingga baris yang sudah DIANGKAT dari rak — ditarik ke produksi lewat
     * ProductionController::closeStorageForReprocessed, atau di-void oleh
     * `repackage()` di bawah — tetap terdaftar sebagai stok kedaluwarsa di gudang
     * padahal barangnya sudah tidak ada di sana. Tanpa ini, batch yang baru saja
     * dikemas ulang akan nangkring terus di daftar dan tombolnya bisa ditekan
     * berulang kali.
     */
    private function baseQuery(string $threshold): Builder
    {
        return InstrumentStorage::stillInRack()
            ->whereNull('order_id')
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
