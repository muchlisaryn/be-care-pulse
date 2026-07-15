<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\Packaging;
use App\Models\PackagingType;
use App\Models\PipelineEvent;
use App\Models\Sterilization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tahap Inspection & Packaging pada pipeline pemrosesan CSSD (record PKG-NNN).
 *
 * Dirangkai ke tahap cleaning lewat washing_code, dan ke produksi lewat rantai
 * washing.production_code. Unit fisik sudah dikunci sejak tahap Produksi, jadi
 * di sini tidak ada generate/scan unit — cukup tampilkan isi lalu tandai selesai.
 * Saat "Selesai Packaging", record lanjut jadi kandidat tahap Sterilisasi.
 */
class PackagingController extends Controller
{
    /** Relasi rantai untuk memuat unit fisik batch (packaging → washing → produksi). */
    private const CHAIN = [
        'washing.production.items.instrumentStock.instrument',
        'washing.production.items.conditionOut',
        'packagingType',
    ];

    /**
     * Daftar batch pada tahap Packaging: record `packaging` yang masih diproses
     * (belum ditandai selesai / belum lanjut ke sterilisasi).
     */
    public function index(Request $request): JsonResponse
    {
        $packagings = Packaging::with(self::CHAIN)
            ->whereIn('status', [Packaging::STATUS_DIPROSES, Packaging::STATUS_SELESAI])
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('washing_code', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%"))
            )
            ->orderByDesc('id')
            ->paginate(20);

        $packagings->getCollection()->transform(fn (Packaging $p) => $this->transform($p));

        return $this->success('Data tahap packaging berhasil diambil.', $packagings);
    }

    /**
     * Tandai "Selesai Packaging" (Inspection & Packaging selesai) → record
     * packaging selesai & lanjut menjadi kandidat tahap Sterilisasi. Wajib
     * menyertakan nomor lot/batch indikator kimia internal. Mengembalikan data
     * label sterilisasi untuk dicetak (nama set, batch, petugas, expiry otomatis).
     */
    public function complete(Request $request, Packaging $packaging): JsonResponse
    {
        if ($packaging->status !== Packaging::STATUS_DIPROSES) {
            return $this->error('Batch packaging ini sudah diselesaikan.', 422);
        }

        $validated = $request->validate([
            'chemical_indicator' => 'required|string|max:255',
            'operator' => 'nullable|string|max:255',
            'packaged_at' => 'nullable|date',
            // Jenis kemasan menentukan masa simpan steril → tgl kedaluwarsa batch.
            // Hanya jenis yang belum dihapus yang boleh dipilih.
            'packaging_type_id' => [
                'required',
                Rule::exists('packaging_types', 'id')->whereNull('deleted_by'),
            ],
            'note' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($validated, $packaging) {
                $actor = auth()->user()?->name;

                $packaging->status = Packaging::STATUS_SELESAI;
                $packaging->chemical_indicator = $validated['chemical_indicator'];
                $packaging->operator = $validated['operator'] ?? $packaging->operator ?? $actor;
                $packaging->packaged_at = $validated['packaged_at'] ?? now();
                $packaging->packaging_type_id = $validated['packaging_type_id'];
                // Snapshot tanggal: dihitung sekali di sini agar tidak ikut bergeser
                // bila masa simpan jenis kemasan diubah admin di kemudian hari.
                $shelfLife = PackagingType::findOrFail($validated['packaging_type_id'])->shelf_life_days;
                $packaging->expiry_date = $packaging->packaged_at
                    ->copy()
                    ->addDays($shelfLife)
                    ->toDateString();
                $packaging->note = $validated['note'] ?? $packaging->note;
                $packaging->started_by ??= $actor;
                $packaging->started_at ??= now();
                $packaging->completed_by = $actor;
                $packaging->completed_at = now();
                $packaging->save();

                PipelineEvent::record(PipelineEvent::STAGE_PACKAGING, $packaging->code, PipelineEvent::ACTION_SELESAI, [
                    'note' => 'Packaging selesai — indikator kimia '.$validated['chemical_indicator'].' — siap sterilisasi',
                ]);

                // Perbarui tahap unit (keluar dari pengemasan → siap sterilisasi).
                $stockIds = $packaging->washing?->production?->items()->pluck('instrument_stock_id')->all() ?? [];
                InstrumentStock::syncStages($stockIds);
            });

            $packaging->refresh();

            return $this->success('Packaging selesai — batch siap masuk tahap sterilisasi.', [
                ...$this->transform($packaging),
                'label' => $this->labelPayload($packaging),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Ambil ulang data Label Barcode Sterilisasi sebuah batch (untuk dilihat /
     * dicetak ulang kapan saja setelah packaging selesai). Data label tetap
     * dihitung dari record packaging yang tersimpan, jadi tidak hilang meski
     * modal label sebelumnya sudah ditutup.
     */
    public function label(Packaging $packaging): JsonResponse
    {
        return $this->success('Label sterilisasi berhasil diambil.', [
            'label' => $this->labelPayload($packaging),
        ]);
    }

    /**
     * Data Label Barcode Sterilisasi untuk dicetak saat packaging selesai:
     * nama set, nomor batch, petugas pengemas, tgl kemas, jenis kemasan, tgl
     * kedaluwarsa (dari masa simpan jenis kemasan), indikator kimia, + satu
     * label per unit.
     */
    private function labelPayload(Packaging $packaging): array
    {
        $packaging->loadMissing(self::CHAIN);

        $production = $packaging->washing?->production;
        $units = $production ? $production->items : collect();

        $packagedAt = $packaging->packaged_at ?? now();
        // Batch lama (dikemas sebelum kolom expiry_date ada) tetap pakai aturan default.
        $expiry = $packaging->expiry_date?->toDateString()
            ?? $packagedAt->copy()->addDays(Sterilization::STERILE_SHELF_LIFE_DAYS)->toDateString();

        return [
            'batch' => $production?->code ?? $packaging->code, // Nomor Batch (PRD / PKG)
            'packaging_code' => $packaging->code,
            'set_name' => $production?->displayName() ?? 'Produksi CSSD',
            'packer' => $packaging->operator,
            'packaging_type' => $packaging->packagingType?->name,
            'packaged_at' => $packagedAt->toIso8601String(),
            'expiry_date' => $expiry,
            'chemical_indicator' => $packaging->chemical_indicator,
            'items' => $units->map(fn ($u) => [
                'instrument_name' => $u->instrumentStock?->instrument?->name ?? 'Instrumen',
                'unit_code' => $u->instrumentStock?->code,
                'source' => $u->source,
                'package_name' => $u->package_name,
            ])->values(),
        ];
    }

    /** Bentuk respons satu batch packaging agar cocok dengan tipe di frontend. */
    private function transform(Packaging $packaging): array
    {
        $packaging->loadMissing(self::CHAIN);

        $production = $packaging->washing?->production;
        $units = $production ? $production->items : collect();

        // Ringkasan chip kartu: unit paket dikelompokkan per paket; satuan per instrumen.
        $items = $units
            ->groupBy(fn ($u) => $u->source === 'paket'
                ? 'paket|'.($u->package_name ?? 'Paket')
                : 'satuan|'.($u->instrumentStock?->instrument?->name ?? 'Instrumen'))
            ->map(function ($group) {
                $first = $group->first();
                $isPaket = $first->source === 'paket';

                return [
                    'type' => $isPaket ? 'paket' : 'satuan',
                    'name' => $isPaket
                        ? ($first->package_name ?? 'Paket')
                        : ($first->instrumentStock?->instrument?->name ?? 'Instrumen'),
                    'quantity' => $group->count(),
                ];
            })
            ->values();

        return [
            'id' => $packaging->id,
            'code' => $packaging->code,                       // PKG-NNN
            'code_transaction' => $production?->code,         // PRD-NNN (ditampilkan di kartu)
            'washing_code' => $packaging->washing_code,       // WSH-NNN
            'status' => 'pengemasan',
            'stage_status' => $packaging->status,             // diproses | selesai (batch sudah dikemas)
            'borrowed_by' => $production?->displayName(),
            'processed_at' => $production?->completed_at ?? $packaging->started_at,
            'processed_by' => $packaging->started_by,
            // Petugas yang menyelesaikan pengemasan + waktunya (untuk riwayat).
            'completed_by' => $packaging->completed_by,
            'completed_at' => $packaging->completed_at,
            'operator' => $packaging->operator,
            'chemical_indicator' => $packaging->chemical_indicator, // = No. Lot indikator kimia
            'packaging_type_id' => $packaging->packaging_type_id,
            'packaging_type_label' => $packaging->packagingType?->name,
            'packaged_at' => $packaging->packaged_at,
            'expiry_date' => $packaging->expiry_date?->toDateString(),
            'units_count' => $units->count(),
            'items' => $items,
            'units' => $units->map(fn ($u) => [
                'id' => $u->id,
                'source' => $u->source,
                'package_name' => $u->package_name,
                'instrument_stock_id' => $u->instrument_stock_id,
                'code' => $u->instrumentStock?->code,
                'instrument' => $u->instrumentStock?->instrument
                    ? [
                        'id' => $u->instrumentStock->instrument->id,
                        'name' => $u->instrumentStock->instrument->name,
                        'image_url' => $u->instrumentStock->instrument->image_url,
                    ]
                    : null,
                'status' => $u->instrumentStock?->status,
                'condition_out' => $u->conditionOut
                    ? ['id' => $u->conditionOut->id, 'name' => $u->conditionOut->name]
                    : null,
            ])->values(),
        ];
    }
}
