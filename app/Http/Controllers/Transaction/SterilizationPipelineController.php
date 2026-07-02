<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\Packaging;
use App\Models\PipelineEvent;
use App\Models\Sterilization;
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

        // Batch STR yang sedang diproses (menunggu validasi) dari pipeline produksi.
        $batches = Sterilization::with(['packagings.washing.production.items.instrumentStock.instrument', 'items.instrumentStock.instrument'])
            ->where('status', Sterilization::STATUS_DIPROSES)
            ->whereNull('order_id')
            ->when($search, fn ($q, $s) => $q->where('code', 'like', "%{$s}%"))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Sterilization $b) => $this->batchPayload($b));

        $items = $batches->concat($ready)->values();

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
            'packaging_ids' => 'required|array|min:1',
            'packaging_ids.*' => 'integer',
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
            ->whereIn('id', $validated['packaging_ids'])
            ->where('status', Packaging::STATUS_SELESAI)
            ->whereNull('sterilization_id')
            ->get();

        if ($packagings->isEmpty()) {
            return $this->error('Tidak ada batch packaging siap-steril yang valid dipilih.', 422);
        }

        $stockIds = $packagings
            ->flatMap(fn (Packaging $p) => ($p->washing?->production?->items ?? collect())->pluck('instrument_stock_id'))
            ->filter()->unique()->values();

        if ($stockIds->isEmpty()) {
            return $this->error('Batch terpilih tidak punya unit untuk disterilkan.', 422);
        }

        try {
            $sterilization = DB::transaction(function () use ($validated, $packagings, $stockIds) {
                $sterilization = Sterilization::create([
                    ...collect($validated)->except('packaging_ids')->all(),
                    'packaging_code' => $packagings->first()->code, // referensi utama
                    'method' => $validated['method'] ?? Sterilization::METHOD_UAP,
                    'status' => Sterilization::STATUS_DIPROSES,
                ]);

                foreach ($stockIds as $stockId) {
                    $sterilization->items()->create(['instrument_stock_id' => $stockId]);
                }

                // Tandai tiap PKG masuk batch ini.
                Packaging::whereIn('id', $packagings->pluck('id'))->update(['sterilization_id' => $sterilization->id]);

                InstrumentStock::transitionMany($stockIds->all(), InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'sterilization',
                    'reference' => $sterilization->code,
                ]);

                PipelineEvent::record(PipelineEvent::STAGE_STERILIZATION, $sterilization->code, PipelineEvent::ACTION_DIBUAT, [
                    'note' => 'Batch sterilisasi dibuat dari '.$packagings->count().' packaging: '.$packagings->pluck('code')->implode(', '),
                ]);

                return $sterilization;
            });

            return $this->success('Batch sterilisasi berhasil dibuat.', $this->batchPayload($sterilization->refresh()), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Validasi hasil sebuah batch sterilisasi (STR diproses).
     * - selesai (Steril): batch selesai + expiry, unit → tersedia (steril).
     * - gagal: batch gagal, unit dibebaskan; PKG anggota kembali siap-steril.
     */
    public function validateResult(Request $request, Sterilization $sterilization): JsonResponse
    {
        if ($sterilization->status !== Sterilization::STATUS_DIPROSES) {
            return $this->error('Batch ini tidak sedang diproses.', 422);
        }

        $validated = $request->validate([
            'result' => ['required', Rule::in([Sterilization::STATUS_SELESAI, Sterilization::STATUS_GAGAL])],
            'chemical_indicator' => 'nullable|string|max:100',
            'biological_indicator' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($validated, $sterilization) {
                $steril = $validated['result'] === Sterilization::STATUS_SELESAI;

                $sterilization->fill(array_filter([
                    'chemical_indicator' => $validated['chemical_indicator'] ?? null,
                    'biological_indicator' => $validated['biological_indicator'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'note' => $validated['note'] ?? null,
                ], fn ($v) => $v !== null));
                $sterilization->status = $validated['result'];
                $sterilization->completed_by = auth()->user()?->name;
                $sterilization->completed_at = now();

                if ($steril && $sterilization->expiry_date === null) {
                    $base = $sterilization->sterilized_at ? $sterilization->sterilized_at->copy() : now();
                    $sterilization->expiry_date = $base->addDays(Sterilization::STERILE_SHELF_LIFE_DAYS)->toDateString();
                }

                $sterilization->save();

                $stockIds = $sterilization->items()->pluck('instrument_stock_id')->all();
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_TERSEDIA, [
                    'context' => 'sterilization',
                    'reference' => $sterilization->code,
                ]);

                // Gagal → lepaskan PKG anggota agar bisa dibuatkan batch ulang.
                if (! $steril) {
                    Packaging::where('sterilization_id', $sterilization->id)->update(['sterilization_id' => null]);
                }

                PipelineEvent::record(
                    PipelineEvent::STAGE_STERILIZATION,
                    $sterilization->code,
                    $steril ? PipelineEvent::ACTION_SELESAI : PipelineEvent::ACTION_GAGAL,
                    ['note' => $steril ? 'Sterilisasi tervalidasi (steril & siap rilis)' : 'Gagal steril, wajib re-proses'],
                );
            });

            return $this->success(
                $validated['result'] === Sterilization::STATUS_SELESAI
                    ? 'Sterilisasi tervalidasi: alat steril & siap rilis.'
                    : 'Sterilisasi ditandai gagal: batch kembali ke antrean siap-steril.',
                ['sterilization_code' => $sterilization->code]
            );
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

    /** Item "menunggu validasi" dari satu batch STR (gabungan PKG). */
    private function batchPayload(Sterilization $batch): array
    {
        $batch->loadMissing(['packagings.washing.production.items.instrumentStock.instrument', 'items.instrumentStock.instrument']);

        // Kumpulan unit produksi dari semua PKG anggota (punya source/package_name).
        $units = $batch->packagings
            ->flatMap(fn (Packaging $p) => $p->washing?->production?->items ?? collect())
            ->values();

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
            'image_url' => $this->batchImage($units),
            'processed_at' => $batch->sterilized_at,
            'unit_count' => $units->count(),
            'units' => $units->map(fn ($u) => $this->unitRow($u))->values(),
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
                'status' => $batch->status,
            ],
        ];
    }

    /** Baris unit (production_item) untuk daftar. */
    private function unitRow($u): array
    {
        return [
            'id' => $u->id,
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
