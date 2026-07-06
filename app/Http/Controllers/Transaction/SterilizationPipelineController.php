<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\Packaging;
use App\Models\PipelineEvent;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tahap Sterilisasi pada pipeline pemrosesan CSSD — berbasis record Packaging (PKG).
 *
 * Satu batch sterilisasi (STR) bisa MENGGABUNGKAN beberapa PKG (campuran produksi
 * satuan/paket) agar disterilkan bersamaan. Keanggotaan dicatat lewat
 * `packaging.sterilization_id`. Alur: PKG selesai (siap-steril) → pilih beberapa →
 * buat batch (STR) → validasi Steril/Gagal.
 */
class SterilizationPipelineController extends Controller
{
    /** Relasi rantai untuk memuat unit fisik sebuah packaging (→ washing → produksi). */
    private const CHAIN = [
        'washing.production.items.instrumentStock.instrument',
        'washing.washerMachine',
    ];

    /**
     * Daftar pipeline sterilisasi:
     * - item "siap-steril" (kind=ready): PKG selesai yang belum masuk batch.
     * - item "menunggu validasi" (kind=batch): batch STR diproses (gabungan PKG).
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->search;

        // PKG siap-steril (belum tergabung ke batch).
        $ready = Packaging::with(self::CHAIN)
            ->where('status', Packaging::STATUS_SELESAI)
            ->whereNull('sterilization_id')
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                ->orWhere('washing_code', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Packaging $p) => $this->readyPayload($p));

        // Batch STR dari pipeline produksi: yang sedang diproses (menunggu validasi)
        // + yang sudah divalidasi (selesai/gagal) sebagai RIWAYAT — agar batch steril
        // tidak hilang dari tampilan setelah divalidasi. FE memisahkan lewat
        // `sterilization.status`.
        $batches = Sterilization::with(['packagings.washing.production.items.instrumentStock.instrument', 'items.instrumentStock.instrument'])
            ->whereIn('status', [Sterilization::STATUS_DIPROSES, Sterilization::STATUS_SELESAI, Sterilization::STATUS_GAGAL])
            ->whereNull('order_id')
            ->when($search, fn ($q, $s) => $q->where('code', 'like', "%{$s}%"))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Sterilization $b) => $this->batchPayload($b));

        // Unit re-proses: unit yang gagal steril (sterilization_item TERBARU-nya
        // 'gagal') → kembali antre sebagai unit lepas, terpisah dari tray asalnya.
        // Otomatis hilang dari antrean begitu unit di-batch ulang (item baru dibuat).
        $latestItemIds = SterilizationItem::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('instrument_stock_id')
            ->pluck('id');

        $reproc = SterilizationItem::with(['instrumentStock.instrument', 'sterilization'])
            ->whereIn('id', $latestItemIds)
            ->where('result', Sterilization::RESULT_GAGAL)
            ->when($search, fn ($q, $s) => $q->whereHas('instrumentStock', fn ($w) => $w->where('code', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->get()
            ->map(fn (SterilizationItem $it) => $this->reprocPayload($it));

        $items = $batches->concat($reproc)->concat($ready)->values();

        // Bentuk seperti paginator (satu halaman) agar cocok dengan pemanggil FE.
        return $this->success('Data pipeline sterilisasi berhasil diambil.', [
            'data' => $items,
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $items->count(),
            'total' => $items->count(),
        ]);
    }

    /**
     * Buat SATU batch sterilisasi (STR) dari beberapa PKG siap-steril terpilih.
     * Seluruh unit tiap PKG masuk ke batch, unit → sterilisasi, tiap PKG ditandai
     * `sterilization_id` batch tersebut.
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'packaging_ids' => 'nullable|array',
            'packaging_ids.*' => 'integer',
            'reproc_stock_ids' => 'nullable|array',
            'reproc_stock_ids.*' => 'integer',
            'machine' => 'required|string|max:255',
            'method' => ['nullable', Rule::in(Sterilization::METHODS)],
            'cycle_number' => 'nullable|string|max:100',
            'temperature' => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer|min:0',
            'operator' => 'nullable|string|max:255',
            'sterilized_at' => 'required|date',
            'expiry_date' => 'nullable|date|after_or_equal:sterilized_at',
            'chemical_indicator' => 'nullable|string|max:100',
            'biological_indicator' => 'nullable|string|max:100',
            'note' => 'nullable|string',
        ]);

        $packagings = Packaging::with(self::CHAIN)
            ->whereIn('id', $validated['packaging_ids'] ?? [])
            ->where('status', Packaging::STATUS_SELESAI)
            ->whereNull('sterilization_id')
            ->get();

        // Unit dari tray (PKG) terpilih.
        $stockIds = $packagings
            ->flatMap(fn (Packaging $p) => ($p->washing?->production?->items ?? collect())->pluck('instrument_stock_id'))
            ->filter();

        // Unit re-proses lepas terpilih — hanya yang benar-benar butuh re-steril
        // (sterilization_item terbaru = gagal), supaya tak bisa mem-batch sembarang unit.
        $reprocIds = collect($validated['reproc_stock_ids'] ?? [])->filter()->unique();
        if ($reprocIds->isNotEmpty()) {
            $latestItemIds = SterilizationItem::query()->selectRaw('MAX(id) as id')->groupBy('instrument_stock_id')->pluck('id');
            $validReproc = SterilizationItem::whereIn('id', $latestItemIds)
                ->where('result', Sterilization::RESULT_GAGAL)
                ->whereIn('instrument_stock_id', $reprocIds->all())
                ->pluck('instrument_stock_id');
            $stockIds = $stockIds->concat($validReproc);
        }

        $stockIds = $stockIds->unique()->values();

        if ($stockIds->isEmpty()) {
            return $this->error('Tidak ada unit siap-steril yang valid dipilih.', 422);
        }

        // Tgl kedaluwarsa steril: pakai input operator bila diisi; bila kosong,
        // hitung dari tgl kemas paling awal + batas steril mesin washer yang dipakai
        // saat cleaning. Bila beberapa tray beda mesin, ambil masa simpan TERPENDEK
        // (paling aman). Fallback ke masa simpan default bila mesin tak punya batas.
        $expiryDate = $validated['expiry_date'] ?? null;
        if ($expiryDate === null) {
            $shelfLifeDays = $packagings
                ->map(fn (Packaging $p) => $p->washing?->washerMachine?->sterile_shelf_life_days)
                ->filter()
                ->min() ?? Sterilization::STERILE_SHELF_LIFE_DAYS;

            $packagedAt = $packagings->map(fn (Packaging $p) => $p->packaged_at)->filter()->sort()->first();
            $base = $packagedAt
                ? \Illuminate\Support\Carbon::parse($packagedAt)
                : \Illuminate\Support\Carbon::parse($validated['sterilized_at']);
            $expiryDate = $base->addDays($shelfLifeDays)->toDateString();
        }

        $reprocCount = $stockIds->count() - $packagings->flatMap(fn (Packaging $p) => ($p->washing?->production?->items ?? collect())->pluck('instrument_stock_id'))->filter()->unique()->count();

        try {
            $sterilization = DB::transaction(function () use ($validated, $packagings, $stockIds, $expiryDate, $reprocCount) {
                $sterilization = Sterilization::create([
                    ...collect($validated)->except(['packaging_ids', 'reproc_stock_ids'])->all(),
                    'packaging_code' => $packagings->first()?->code ?? 'REPROSES', // referensi utama
                    'method' => $validated['method'] ?? Sterilization::METHOD_UAP,
                    'expiry_date' => $expiryDate,
                    'status' => Sterilization::STATUS_DIPROSES,
                ]);

                foreach ($stockIds as $stockId) {
                    $sterilization->items()->create(['instrument_stock_id' => $stockId]);
                }

                // Tandai tiap PKG masuk batch ini.
                if ($packagings->isNotEmpty()) {
                    Packaging::whereIn('id', $packagings->pluck('id'))->update(['sterilization_id' => $sterilization->id]);
                }

                InstrumentStock::transitionMany($stockIds->all(), InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'sterilization',
                    'reference' => $sterilization->code,
                ]);

                // Perbarui tahap unit (→ sterilisasi).
                InstrumentStock::syncStages($stockIds->all());

                $sumber = [];
                if ($packagings->isNotEmpty()) {
                    $sumber[] = $packagings->count().' packaging ('.$packagings->pluck('code')->implode(', ').')';
                }
                if ($reprocCount > 0) {
                    $sumber[] = $reprocCount.' unit re-proses';
                }

                PipelineEvent::record(PipelineEvent::STAGE_STERILIZATION, $sterilization->code, PipelineEvent::ACTION_DIBUAT, [
                    'note' => 'Batch sterilisasi dibuat dari '.implode(' + ', $sumber),
                ]);

                return $sterilization;
            });

            return $this->success('Batch sterilisasi berhasil dibuat.', $this->batchPayload($sterilization->refresh()), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Validasi hasil sterilisasi **per unit**: operator mencentang tiap alat
     * berhasil / gagal steril. `failed_stock_ids` = daftar instrument_stock_id yang
     * GAGAL; unit lain dianggap berhasil.
     * - Unit berhasil → steril & siap rilis (status `tersedia`), item `result=berhasil`.
     * - Unit gagal → tetap belum steril, item `result=gagal` → muncul lagi di antrean
     *   sebagai **unit re-proses lepas** (tidak ikut batch berhasil).
     * - Status batch: `selesai` bila ada ≥1 unit berhasil, selain itu `gagal`.
     */
    public function validateResult(Request $request, Sterilization $sterilization): JsonResponse
    {
        if ($sterilization->status !== Sterilization::STATUS_DIPROSES) {
            return $this->error('Batch ini tidak sedang diproses.', 422);
        }

        $validated = $request->validate([
            'failed_stock_ids' => 'nullable|array',
            'failed_stock_ids.*' => 'integer',
            // Indikator kimia wajib diisi saat validasi hasil sterilisasi.
            'chemical_indicator' => 'required|string|max:100',
            'biological_indicator' => 'nullable|string|max:100',
            // Indikator biologis: pembanding (kontrol) & uji — Negatif / Positif.
            'bio_indicator_control' => ['nullable', Rule::in(['Negatif', 'Positif'])],
            'bio_indicator_test' => ['nullable', Rule::in(['Negatif', 'Positif'])],
            'note' => 'nullable|string',
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $sterilization) {
                $failedIds = collect($validated['failed_stock_ids'] ?? [])->map(fn ($v) => (int) $v)->unique();

                $passed = [];
                $failed = [];
                foreach ($sterilization->items()->get() as $item) {
                    $isFailed = $failedIds->contains((int) $item->instrument_stock_id);
                    $item->result = $isFailed ? Sterilization::RESULT_GAGAL : Sterilization::RESULT_BERHASIL;
                    $item->save();
                    $isFailed ? $failed[] = $item->instrument_stock_id : $passed[] = $item->instrument_stock_id;
                }

                $anyPassed = count($passed) > 0;

                $sterilization->fill(array_filter([
                    'chemical_indicator' => $validated['chemical_indicator'] ?? null,
                    'biological_indicator' => $validated['biological_indicator'] ?? null,
                    'bio_indicator_control' => $validated['bio_indicator_control'] ?? null,
                    'bio_indicator_test' => $validated['bio_indicator_test'] ?? null,
                    'note' => $validated['note'] ?? null,
                ], fn ($v) => $v !== null));
                $sterilization->status = $anyPassed ? Sterilization::STATUS_SELESAI : Sterilization::STATUS_GAGAL;
                $sterilization->completed_by = auth()->user()?->name;
                $sterilization->completed_at = now();

                if ($anyPassed && $sterilization->expiry_date === null) {
                    $base = $sterilization->sterilized_at ? $sterilization->sterilized_at->copy() : now();
                    $sterilization->expiry_date = $base->addDays(Sterilization::STERILE_SHELF_LIFE_DAYS)->toDateString();
                }
                $sterilization->save();

                // Unit berhasil → steril & siap rilis.
                if ($passed) {
                    InstrumentStock::transitionMany($passed, InstrumentStock::STATUS_TERSEDIA, [
                        'context' => 'sterilization',
                        'reference' => $sterilization->code,
                    ]);
                }

                // Unit gagal → tetap 'sterilisasi' (belum steril); muncul di antrean
                // re-proses sebagai unit lepas (item terbaru = gagal).
                if ($failed) {
                    InstrumentStock::transitionMany($failed, InstrumentStock::STATUS_STERILISASI, [
                        'context' => 'sterilization',
                        'reference' => $sterilization->code.' — gagal, re-proses',
                    ]);
                }

                // Perbarui tahap unit setelah validasi (berhasil → tersedia/lanjut simpan;
                // gagal → kembali antre proses).
                InstrumentStock::syncStages(array_merge($passed, $failed));

                PipelineEvent::record(
                    PipelineEvent::STAGE_STERILIZATION,
                    $sterilization->code,
                    $anyPassed ? PipelineEvent::ACTION_SELESAI : PipelineEvent::ACTION_GAGAL,
                    ['note' => 'Validasi per unit: '.count($passed).' berhasil, '.count($failed).' gagal (re-proses)'],
                );

                return ['passed' => count($passed), 'failed' => count($failed)];
            });

            $msg = $result['failed'] === 0
                ? "Validasi tersimpan: {$result['passed']} unit steril & siap rilis."
                : ($result['passed'] === 0
                    ? "Validasi tersimpan: semua {$result['failed']} unit gagal → kembali ke antrean re-proses."
                    : "Validasi tersimpan: {$result['passed']} unit steril, {$result['failed']} unit gagal → antre re-proses.");

            return $this->success($msg, ['sterilization_code' => $sterilization->code, ...$result]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Item "siap-steril" dari satu PKG (belum masuk batch). */
    private function readyPayload(Packaging $packaging): array
    {
        $production = $packaging->washing?->production;
        $units = $production ? $production->items : collect();

        return [
            'id' => $packaging->id,     // id PKG → dipakai saat memilih untuk batch
            'kind' => 'ready',
            'reprocess' => false,
            'stock_id' => null,
            'code' => $packaging->code, // PKG-NNN
            'code_transaction' => $production?->code,
            'status' => 'selesai',
            'borrowed_by' => $production?->displayName(),
            'image_url' => $this->batchImage($units),
            'processed_at' => $packaging->completed_at ?? $packaging->packaged_at,
            'unit_count' => $units->count(),
            'units' => $units->map(fn ($u) => $this->unitRow($u))->values(),
            'sterilization' => null,
        ];
    }

    /** Id sintetis untuk entri re-proses unit lepas agar tidak bentrok dengan id PKG. */
    private const REPROC_ID_BASE = 1000000000;

    /**
     * Item "siap-steril" untuk satu unit re-proses (gagal steril sebelumnya).
     * Ditampilkan sebagai unit lepas — bukan tray — agar bisa di-batch sendiri.
     */
    private function reprocPayload(SterilizationItem $item): array
    {
        $stock = $item->instrumentStock;
        $name = $stock?->instrument?->name ?? 'Instrumen';

        return [
            'id' => self::REPROC_ID_BASE + (int) $item->instrument_stock_id,
            'kind' => 'ready',
            'reprocess' => true,
            'stock_id' => $item->instrument_stock_id,
            'code' => $stock?->code ?? "#{$item->instrument_stock_id}",
            'code_transaction' => $item->sterilization?->code, // batch asal yang gagal
            'status' => 'selesai',
            'borrowed_by' => $name,
            'image_url' => $stock?->instrument?->image_url,
            'processed_at' => $item->updated_at,
            'unit_count' => 1,
            'units' => [[
                'id' => $item->id,
                'instrument_stock_id' => $item->instrument_stock_id,
                'code' => $stock?->code,
                'instrument' => $name,
                'image_url' => $stock?->instrument?->image_url,
                'source' => 'satuan',
                'package_name' => null,
            ]],
            'sterilization' => null,
        ];
    }

    /** Item "menunggu validasi" dari satu batch STR (gabungan PKG). */
    private function batchPayload(Sterilization $batch): array
    {
        $batch->loadMissing(['packagings.washing.production.items.instrumentStock.instrument', 'items.instrumentStock.instrument']);

        // Unit produksi dari PKG anggota (punya source/package_name) — untuk gambar &
        // memperkaya info tiap unit. Unit re-proses lepas tidak punya PKG.
        $prodUnits = $batch->packagings
            ->flatMap(fn (Packaging $p) => $p->washing?->production?->items ?? collect())
            ->values();
        $prodByStock = $prodUnits->keyBy('instrument_stock_id');

        // Daftar unit = sterilization_items batch (sumber kebenaran: termasuk unit
        // re-proses lepas yang tidak berasal dari PKG).
        $units = $batch->items->map(function ($item) use ($prodByStock) {
            $prod = $prodByStock->get($item->instrument_stock_id);

            return [
                'id' => $item->id,
                'instrument_stock_id' => $item->instrument_stock_id,
                'code' => $item->instrumentStock?->code,
                'instrument' => $item->instrumentStock?->instrument?->name,
                'image_url' => $item->instrumentStock?->instrument?->image_url,
                'source' => $prod?->source ?? 'satuan',
                'package_name' => $prod?->package_name,
                'result' => $item->result,
            ];
        })->values();

        // Nama gabungan (unik) dari tiap produksi anggota.
        $names = $batch->packagings
            ->map(fn (Packaging $p) => $p->washing?->production?->displayName())
            ->filter()->unique()->implode(', ');

        return [
            'id' => $batch->id,          // id STR → dipakai saat validasi
            'kind' => 'batch',
            'code' => $batch->code,      // STR-NNN
            'code_transaction' => $batch->packagings->map(fn ($p) => $p->washing?->production?->code)->filter()->unique()->implode(', '),
            'status' => 'sterilisasi',
            'borrowed_by' => $names ?: 'Produksi CSSD',
            'image_url' => $this->batchImage($prodUnits),
            'processed_at' => $batch->sterilized_at,
            'unit_count' => $units->count(),
            'units' => $units,
            'sterilization' => [
                'id' => $batch->id,
                'code' => $batch->code,
                'machine' => $batch->machine,
                'method' => $batch->method,
                'cycle_number' => $batch->cycle_number,
                'temperature' => $batch->temperature,
                'duration_minutes' => $batch->duration_minutes,
                'sterilized_at' => $batch->sterilized_at,
                'expiry_date' => $batch->expiry_date,
                'chemical_indicator' => $batch->chemical_indicator,
                'biological_indicator' => $batch->biological_indicator,
                'bio_indicator_control' => $batch->bio_indicator_control,
                'bio_indicator_test' => $batch->bio_indicator_test,
                'note' => $batch->note,
                'status' => $batch->status,
                // Jejak petugas: yang membuat/menjalankan batch & yang memvalidasi hasil.
                'processed_by' => $batch->created_by,
                'validated_by' => $batch->completed_by,
                'validated_at' => $batch->completed_at,
            ],
        ];
    }

    /** Baris unit (production_item) untuk daftar. */
    private function unitRow($u): array
    {
        return [
            'id' => $u->id,
            'instrument_stock_id' => $u->instrument_stock_id, // dipakai untuk validasi hasil per-unit
            'code' => $u->instrumentStock?->code,
            'instrument' => $u->instrumentStock?->instrument?->name,
            'image_url' => $u->instrumentStock?->instrument?->image_url,
            'source' => $u->source,
            'package_name' => $u->package_name,
        ];
    }

    /** Gambar utama batch: gambar SET (katalog paket) atau instrumen pertama. */
    private function batchImage($units): ?string
    {
        $paket = $units->firstWhere('source', 'paket');
        if ($paket && $paket->package_name) {
            $catalog = InstrumentCatalog::where('name', $paket->package_name)->first();
            if ($catalog?->image_url) {
                return $catalog->image_url;
            }
        }

        return $units->first()?->instrumentStock?->instrument?->image_url;
    }
}
