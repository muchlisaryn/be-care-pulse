<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Bmhp;
use App\Models\PackageBmhp;
use App\Models\PackagingBmhp;
use App\Models\PackagingBmhpItem;
use App\Models\PackagingType;
use App\Models\ProductionBmhp;
use App\Models\ProductionBmhpItem;
use App\Models\SterilizationBmhp;
use App\Models\SterilizationBmhpItem;
use App\Models\SterilizerMachine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Controller Pipeline Produksi BMHP (Bahan Medis Habis Pakai) — 3 Tahap:
 * 1. Produksi Baru (potong stok master BMHP, langsung ke antrean pengemasan)
 * 2. Inspeksi & Pengemasan (pilih jenis kemasan, cetak label barcode)
 * 3. Sterilisasi (mesin sterilisator, validasi hasil)
 */
class ProductionBmhpController extends Controller
{
    /**
     * Data kandidat untuk form produksi baru:
     * - Daftar BMHP satuan (aktif)
     * - Daftar Paket BMHP (beserta isi itemnya)
     * - Master jenis kemasan
     * - Master mesin sterilisator
     */
    public function candidates(): JsonResponse
    {
        $bmhps = Bmhp::whereNull('deleted_by')
            ->orderBy('name')
            ->get();

        $packages = PackageBmhp::with(['items.bmhp.unitMaster'])
            ->whereNull('deleted_by')
            ->orderBy('name')
            ->get();

        $packagingTypes = PackagingType::whereNull('deleted_by')
            ->orderBy('name')
            ->get();

        $machines = SterilizerMachine::where('status', SterilizerMachine::STATUS_AKTIF)
            ->whereNull('deleted_by')
            ->orderBy('name')
            ->get();

        return $this->success('Kandidat produksi BMHP.', [
            'bmhps' => $bmhps,
            'packages' => $packages,
            'packaging_types' => $packagingTypes,
            'machines' => $machines,
        ]);
    }

    /**
     * Buat batch produksi baru BMHP:
     * - Validasi stok cukup di `mst_bmhp`
     * - Potong `stock_qty`
     * - Buat `production_bmhp` & `production_bmhp_item`
     * - Langsung buat `packaging_bmhp` (masuk antrean Inspeksi & Pengemasan)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.type' => ['required', Rule::in(['satuan', 'paket'])],
            'items.*.ref_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                // 1. Akumulasi total kebutuhan per bmhp_id untuk validasi stok
                $requirements = [];
                $packageModels = [];

                foreach ($validated['items'] as $item) {
                    $qty = (float) $item['quantity'];

                    if ($item['type'] === 'satuan') {
                        $bmhpId = (int) $item['ref_id'];
                        $requirements[$bmhpId] = ($requirements[$bmhpId] ?? 0) + $qty;
                    } else {
                        $packageId = (int) $item['ref_id'];
                        if (! isset($packageModels[$packageId])) {
                            $pkg = PackageBmhp::with('items.bmhp')->findOrFail($packageId);
                            $packageModels[$packageId] = $pkg;
                        }
                        $pkg = $packageModels[$packageId];
                        foreach ($pkg->items as $pItem) {
                            $needed = (float) $pItem->qty * $qty;
                            $requirements[$pItem->bmhp_id] = ($requirements[$pItem->bmhp_id] ?? 0) + $needed;
                        }
                    }
                }

                // Cek stok seluruh BMHP yang dibutuhkan
                $bmhpRecords = Bmhp::whereIn('id', array_keys($requirements))->lockForUpdate()->get()->keyBy('id');

                foreach ($requirements as $bmhpId => $neededQty) {
                    $bmhp = $bmhpRecords->get($bmhpId);
                    if (! $bmhp) {
                        throw new \RuntimeException("BMHP ID {$bmhpId} tidak ditemukan.");
                    }
                    if ($bmhp->stock_qty < $neededQty) {
                        throw new \RuntimeException("Stok {$bmhp->name} tidak mencukupi (dibutuhkan {$neededQty} {$bmhp->unit}, tersedia {$bmhp->stock_qty} {$bmhp->unit}).");
                    }
                }

                // 2. Potong stok pada tabel master
                foreach ($requirements as $bmhpId => $neededQty) {
                    $bmhp = $bmhpRecords->get($bmhpId);
                    $bmhp->decrement('stock_qty', $neededQty);
                }

                // 3. Buat batch produksi BMHP
                $production = ProductionBmhp::create([
                    'note' => $validated['note'] ?? null,
                    'status' => ProductionBmhp::STATUS_DIPROSES,
                ]);

                // 4. Buat batch packaging BMHP (langsung meluncur ke tahap pengemasan)
                $packaging = PackagingBmhp::create([
                    'production_bmhp_id' => $production->id,
                    'prefix' => 'PKG',
                    'status' => PackagingBmhp::STATUS_DIPROSES,
                    'note' => $validated['note'] ?? null,
                ]);

                // 5. Simpan baris-baris item produksi dan buat label kemasannya
                foreach ($validated['items'] as $item) {
                    $qty = (float) $item['quantity'];

                    if ($item['type'] === 'satuan') {
                        $bmhp = $bmhpRecords->get($item['ref_id']);
                        $prodItem = $production->items()->create([
                            'source' => 'satuan',
                            'bmhp_id' => $bmhp->id,
                            'name' => $bmhp->name,
                            'quantity' => $qty,
                            'unit_name' => $bmhp->unit,
                        ]);

                        // Label kemasan satuan
                        $packaging->items()->create([
                            'production_bmhp_item_id' => $prodItem->id,
                            'barcode_no' => $packaging->barcodeNoFor(null),
                        ]);
                    } else {
                        $pkg = $packageModels[$item['ref_id']];
                        $intQty = max(1, (int) round($qty));

                        for ($setNo = 1; $setNo <= $intQty; $setNo++) {
                            $prodItem = $production->items()->create([
                                'source' => 'paket',
                                'package_bmhp_id' => $pkg->id,
                                'name' => $pkg->name,
                                'package_name' => $pkg->name,
                                'package_no' => $setNo,
                                'quantity' => 1,
                                'unit_name' => 'paket',
                            ]);

                            // Label kemasan per set paket
                            $packaging->items()->create([
                                'production_bmhp_item_id' => $prodItem->id,
                                'barcode_no' => $packaging->barcodeNoFor($setNo),
                            ]);
                        }
                    }
                }

                return [
                    'production' => $production->load('items'),
                    'packaging' => $packaging->load('items'),
                ];
            });

            return $this->success('Batch produksi BMHP berhasil dibuat dan masuk antrean Inspeksi & Pengemasan.', $result, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Daftar antrean Inspeksi & Pengemasan BMHP
     */
    public function packagingQueue(Request $request): JsonResponse
    {
        $search = $request->input('search');

        $query = PackagingBmhp::with([
            'production.items.bmhp.unitMaster',
            'production.items.package.items.bmhp.unitMaster',
            'packagingType',
            'items.productionItem',
        ])
            ->where('disabled', false)
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('code', 'like', "%{$s}%")
                        ->orWhereHas('production', fn ($p) => $p->where('code', 'like', "%{$s}%"))
                        ->orWhereHas('items.productionItem', fn ($pi) => $pi->where('name', 'like', "%{$s}%"));
                });
            });

        $query = $this->applyDateRange($query, $request, 'created_at');

        $data = $query->orderByDesc('id')->get();

        return $this->success('Antrean pengemasan BMHP.', $data);
    }

    /**
     * Selesaikan Inspeksi & Pengemasan BMHP dan cetak label barcode
     */
    public function packagingComplete(Request $request, PackagingBmhp $packaging): JsonResponse
    {
        if ($packaging->status !== PackagingBmhp::STATUS_DIPROSES) {
            return $this->error('Batch pengemasan ini sudah diselesaikan sebelumnya.', 422);
        }

        $validated = $request->validate([
            'packaging_type_id' => 'required|integer|exists:packaging_types,id',
            'chemical_indicator' => 'required|string|max:255',
            'operator' => 'nullable|string|max:255',
            'packaged_at' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($packaging, $validated) {
                $pkgType = PackagingType::findOrFail($validated['packaging_type_id']);
                $packagedAt = ! empty($validated['packaged_at'])
                    ? Carbon::parse($validated['packaged_at'])
                    : now();

                $shelfDays = $pkgType->shelf_life_days ?? 30;
                $expiryDate = $packagedAt->copy()->addDays($shelfDays)->toDateString();
                $actor = auth()->user()?->name ?? $validated['operator'] ?? 'Petugas';

                $packaging->update([
                    'packaging_type_id' => $pkgType->id,
                    'chemical_indicator' => $validated['chemical_indicator'],
                    'operator' => $validated['operator'] ?? $actor,
                    'packaged_at' => $packagedAt,
                    'expiry_date' => $expiryDate,
                    'note' => $validated['note'] ?? $packaging->note,
                    'status' => PackagingBmhp::STATUS_SELESAI,
                    'completed_by' => $actor,
                    'completed_at' => now(),
                ]);
            });

            $packaging->refresh()->load([
                'production.items.bmhp.unitMaster',
                'production.items.package.items.bmhp.unitMaster',
                'packagingType',
                'items.productionItem',
            ]);

            // Label payload untuk modal cetak
            $labels = [];
            foreach ($packaging->items as $it) {
                $pi = $it->productionItem;
                $labels[] = [
                    'label_code' => $packaging->prefix.' '.$packaging->code.($pi?->package_no ? ' '.$pi->package_no : ''),
                    'barcode_no' => $it->barcode_no,
                    'title' => $pi?->name ?? 'BMHP',
                    'kind' => $pi?->source ?? 'satuan',
                    'package_no' => $pi?->package_no,
                    'operator' => $packaging->operator ?? $packaging->completed_by,
                    'packaged_at' => $packaging->packaged_at?->format('d/m/Y H:i'),
                    'expiry_date' => $packaging->expiry_date?->format('d/m/Y'),
                    'packaging_type' => $packaging->packagingType?->name,
                ];
            }

            return $this->success('Pengemasan selesai — siap masuk tahap sterilisasi.', [
                'packaging' => $packaging,
                'labels' => $labels,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Antrean Sterilisasi BMHP:
     * - `ready`: kemasan BMHP yang sudah selesai dikemas dan siap masuk mesin sterilisator
     * - `batches`: riwayat batch sterilisasi BMHP
     */
    public function sterilizationQueue(Request $request): JsonResponse
    {
        $search = $request->input('search');

        // Kemasan BMHP yang sudah siap steril dan belum masuk siklus sterilisasi aktif
        $batchedPackagingIds = SterilizationBmhpItem::where('disabled', false)
            ->pluck('packaging_bmhp_id')
            ->unique();

        $readyQuery = PackagingBmhp::with([
            'production.items',
            'packagingType',
            'items.productionItem',
        ])
            ->where('status', PackagingBmhp::STATUS_SELESAI)
            ->where('disabled', false)
            ->whereNotIn('id', $batchedPackagingIds)
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('code', 'like', "%{$s}%")
                        ->orWhereHas('items', fn ($it) => $it->where('barcode_no', 'like', "%{$s}%"))
                        ->orWhereHas('items.productionItem', fn ($pi) => $pi->where('name', 'like', "%{$s}%"));
                });
            });

        $ready = $readyQuery->orderByDesc('id')->get();

        // Batch sterilisasi BMHP (dalam proses & riwayat)
        $batchQuery = SterilizationBmhp::with([
            'machine',
            'items.packaging.production.items',
            'items.packaging.packagingType',
        ])
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('code', 'like', "%{$s}%")
                        ->orWhere('machine_name', 'like', "%{$s}%")
                        ->orWhere('operator', 'like', "%{$s}%");
                });
            });

        $batchQuery = $this->applyDateRange($batchQuery, $request, 'COALESCE(sterilized_at, created_at)');
        $batches = $batchQuery->orderByDesc('id')->get();

        return $this->success('Antrean sterilisasi BMHP.', [
            'ready' => $ready,
            'batches' => $batches,
        ]);
    }

    /**
     * Buat siklus batch sterilisasi BMHP baru
     */
    public function sterilizeBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'packaging_ids' => 'required|array|min:1',
            'packaging_ids.*' => 'integer|exists:packaging_bmhp,id',
            'machine_id' => 'required|integer|exists:sterilizer_machines,id',
            'method' => 'nullable|string',
            'cycle_number' => 'nullable|integer',
            'temperature' => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer',
            'operator' => 'nullable|string|max:255',
            'bio_indicator_lot' => 'nullable|string|max:255',
            'chemical_indicator_lot' => 'nullable|string|max:255',
            'note' => 'nullable|string',
        ]);

        try {
            $sterilization = DB::transaction(function () use ($validated) {
                $machine = SterilizerMachine::findOrFail($validated['machine_id']);
                $actor = auth()->user()?->name ?? $validated['operator'] ?? 'Petugas';

                $sterilization = SterilizationBmhp::create([
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->name,
                    'method' => $validated['method'] ?? 'uap',
                    'cycle_number' => $validated['cycle_number'] ?? null,
                    'temperature' => $validated['temperature'] ?? $machine->temperature,
                    'duration_minutes' => $validated['duration_minutes'] ?? $machine->duration_minutes,
                    'operator' => $validated['operator'] ?? $actor,
                    'bio_indicator_lot' => $validated['bio_indicator_lot'] ?? null,
                    'chemical_indicator_lot' => $validated['chemical_indicator_lot'] ?? null,
                    'status' => SterilizationBmhp::STATUS_DIPROSES,
                    'started_at' => now(),
                    'started_by' => $actor,
                    'note' => $validated['note'] ?? null,
                ]);

                foreach ($validated['packaging_ids'] as $pkgId) {
                    $pkg = PackagingBmhp::with('items')->findOrFail($pkgId);
                    $pkg->update(['status' => PackagingBmhp::STATUS_STERILISASI]);

                    foreach ($pkg->items as $it) {
                        $sterilization->items()->create([
                            'packaging_bmhp_id' => $pkg->id,
                            'packaging_barcode' => $it->barcode_no,
                        ]);
                    }
                }

                return $sterilization;
            });

            return $this->success('Batch sterilisasi BMHP berhasil dibuat dan sedang diproses.', $sterilization->load('items', 'machine'), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Validasi hasil sterilisasi BMHP (Steril / Gagal)
     */
    public function validateSterilization(Request $request, SterilizationBmhp $sterilization): JsonResponse
    {
        if ($sterilization->status !== SterilizationBmhp::STATUS_DIPROSES) {
            return $this->error('Batch sterilisasi ini sudah divalidasi sebelumnya.', 422);
        }

        $validated = $request->validate([
            'result' => ['required', Rule::in(['steril', 'gagal'])],
            'note' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($sterilization, $validated) {
                $isSteril = $validated['result'] === 'steril';
                $actor = auth()->user()?->name ?? 'Petugas';

                $packagingIds = $sterilization->items()->pluck('packaging_bmhp_id')->unique();
                $minExpiry = PackagingBmhp::whereIn('id', $packagingIds)->min('expiry_date');

                $sterilization->update([
                    'status' => $isSteril ? SterilizationBmhp::STATUS_SELESAI : SterilizationBmhp::STATUS_GAGAL,
                    'sterilized_at' => now(),
                    'expiry_date' => $sterilization->expiry_date ?? $minExpiry,
                    'completed_at' => now(),
                    'completed_by' => $actor,
                    'note' => $validated['note'] ?? $sterilization->note,
                ]);

                $sterilization->items()->update([
                    'result' => $validated['result'],
                ]);

                // Update status kemasan
                if ($isSteril) {
                    PackagingBmhp::whereIn('id', $packagingIds)->update([
                        'status' => 'selesai',
                    ]);
                } else {
                    // Jika gagal steril, kemasan di-void (disabled)
                    PackagingBmhp::whereIn('id', $packagingIds)->update([
                        'disabled' => true,
                        'disabled_at' => now(),
                    ]);
                }
            });

            return $this->success(
                $validated['result'] === 'steril'
                    ? 'Validasi berhasil — BMHP telah dinyatakan STERIL dan siap digunakan.'
                    : 'Validasi selesai — batch dinyatakan GAGAL steril.',
                $sterilization->refresh()->load('items', 'machine')
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
