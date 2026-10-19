<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Bmhp;
use App\Models\PackageBmhp;
use App\Models\PackagingBmhpItem;
use App\Models\SterilizationBmhp;
use App\Models\StorageBmhp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller Gudang Steril BMHP:
 * 1. Antrean BMHP steril yang siap disimpan ke rak (incoming)
 * 2. Simpan BMHP steril ke rak penyimpanan (store)
 * 3. Daftar inventaris BMHP di rak steril (inventory)
 * 4. Ringkasan statistik gudang steril BMHP (summary)
 */
class StorageBmhpController extends Controller
{
    private const EXPIRY_ALERT_DAYS = 7;

    /**
     * Batch BMHP steril yang perlu disimpan ke rak gudang steril.
     */
    public function incoming(Request $request): JsonResponse
    {
        $search = $request->input('search');

        $query = SterilizationBmhp::with([
            'machine',
            'items.packaging.items.productionItem',
            'items.packaging.packagingType',
        ])
            ->where('status', SterilizationBmhp::STATUS_SELESAI)
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('code', 'like', "%{$s}%")
                        ->orWhere('machine_name', 'like', "%{$s}%")
                        ->orWhere('operator', 'like', "%{$s}%")
                        ->orWhereHas('items', function ($it) use ($s) {
                            $it->where('packaging_barcode', 'like', "%{$s}%")
                                ->orWhereHas('packaging.items.productionItem', function ($pi) use ($s) {
                                    $pi->where('name', 'like', "%{$s}%");
                                });
                        });
                });
            })
            // Filter: batch yang masih memiliki unit belum tersimpan di rak
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('sterilization_bmhp_items as sbi')
                    ->join('packaging_bmhp_item as pbi', 'pbi.packaging_bmhp_id', '=', 'sbi.packaging_bmhp_id')
                    ->whereColumn('sbi.sterilization_bmhp_id', 'sterilization_bmhp.id')
                    ->where(function ($r) {
                        $r->whereNull('sbi.result')->orWhere('sbi.result', 'steril');
                    })
                    ->where('pbi.disabled', false)
                    ->whereNotExists(function ($sub2) {
                        $sub2->selectRaw('1')
                            ->from('storage_bmhp')
                            ->whereColumn('storage_bmhp.sterilization_bmhp_id', 'sterilization_bmhp.id')
                            ->whereColumn('storage_bmhp.packaging_bmhp_item_id', 'pbi.id')
                            ->whereNull('storage_bmhp.deleted_by');
                    });
            })
            ->orderByDesc('id')
            ->paginate(20);

        $query->getCollection()->transform(fn (SterilizationBmhp $b) => $this->incomingPayload($b));

        return $this->success('Data batch BMHP steril siap disimpan berhasil diambil.', $query);
    }

    /**
     * Format payload batch incoming BMHP.
     */
    private function incomingPayload(SterilizationBmhp $sterilization): array
    {
        // Ambil data storage yang sudah tercatat untuk batch ini
        $storedRecords = StorageBmhp::where('sterilization_bmhp_id', $sterilization->id)
            ->whereNull('deleted_by')
            ->get(['packaging_bmhp_item_id', 'rack_code'])
            ->keyBy('packaging_bmhp_item_id');

        $unitRows = collect();

        foreach ($sterilization->items as $sterilItem) {
            // Hanya kemasan yang hasil sterilisasinya sukses / steril
            if ($sterilItem->result && $sterilItem->result !== 'steril') {
                continue;
            }

            $pkg = $sterilItem->packaging;
            if (! $pkg || $pkg->disabled) {
                continue;
            }

            foreach ($pkg->items as $it) {
                if ($it->disabled) {
                    continue;
                }

                $pi = $it->productionItem;
                $stored = $storedRecords->has($it->id);
                $rackCode = $stored ? $storedRecords->get($it->id)->rack_code : null;

                $unitRows->push([
                    'id' => $it->id, // packaging_bmhp_item_id
                    'code' => $it->barcode_no,
                    'barcode_no' => $it->barcode_no,
                    'instrument' => $pi?->name ?? 'BMHP',
                    'image_url' => null,
                    'source' => $pi?->source ?? 'satuan',
                    'package_name' => $pi?->package_name,
                    'package_no' => $pi?->package_no,
                    'quantity' => $pi?->quantity ?? 1,
                    'unit_name' => $pi?->unit_name ?? 'pcs',
                    'stored' => $stored,
                    'rack_code' => $rackCode,
                ]);
            }
        }

        $unitCount = $unitRows->count();
        $storedCount = $unitRows->where('stored', true)->count();

        return [
            'id' => $sterilization->id,
            // Batch tidak punya kode sendiri — yang ditampilkan kode kemasan di dalamnya.
            'code' => $sterilization->items
                ->map(fn ($it) => $it->packaging ? $it->packaging->prefix.$it->packaging->code : null)
                ->filter()
                ->unique()
                ->implode(', '),
            'code_transaction' => null,
            'status' => $sterilization->status,
            'source' => 'produksi',
            'store_url' => "/master/storage-bmhp/{$sterilization->id}/store",
            'borrowed_by' => null,
            'room' => null,
            'operator' => $sterilization->operator,
            'machine_name' => $sterilization->machine_name ?? $sterilization->machine?->name,
            'processed_at' => $sterilization->sterilized_at?->toISOString() ?? $sterilization->created_at?->toISOString(),
            'expiry_date' => $sterilization->expiry_date?->toDateString(),
            'unit_count' => $unitCount,
            'stored_count' => $storedCount,
            'units' => $unitRows->values()->all(),
        ];
    }

    /**
     * Simpan unit-unit BMHP ke rak gudang steril BMHP.
     * Request body items: [{ packaging_item_id, rack_code }]
     */
    public function store(Request $request, SterilizationBmhp $sterilization): JsonResponse
    {
        if ($sterilization->status !== SterilizationBmhp::STATUS_SELESAI) {
            return $this->error('Batch ini belum steril / tidak siap disimpan.', 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.packaging_item_id' => 'required|integer|exists:packaging_bmhp_item,id',
            'items.*.rack_code' => 'required|string|max:255',
        ]);

        $itemIds = collect($validated['items'])->pluck('packaging_item_id')->all();

        // Cari item yang valid milik batch sterilisasi ini
        $validItems = PackagingBmhpItem::with(['packaging', 'productionItem'])
            ->whereIn('id', $itemIds)
            ->whereHas('packaging.sterilizationItems', function ($q) use ($sterilization) {
                $q->where('sterilization_bmhp_id', $sterilization->id)
                    ->where(fn ($w) => $w->whereNull('result')->orWhere('result', 'steril'));
            })
            ->where('disabled', false)
            ->get()
            ->keyBy('id');

        $alreadyStored = StorageBmhp::where('sterilization_bmhp_id', $sterilization->id)
            ->whereNull('deleted_by')
            ->pluck('packaging_bmhp_item_id')
            ->all();

        try {
            DB::transaction(function () use ($validated, $sterilization, $validItems, &$alreadyStored) {
                $actor = auth()->user()?->name ?? 'Petugas';

                foreach ($validated['items'] as $item) {
                    $itemId = (int) $item['packaging_item_id'];
                    $rackCode = trim($item['rack_code']);

                    if (! $validItems->has($itemId) || in_array($itemId, $alreadyStored, true) || empty($rackCode)) {
                        continue;
                    }

                    $pkgItem = $validItems->get($itemId);
                    $pkg = $pkgItem->packaging;
                    $prodItem = $pkgItem->productionItem;

                    $expiry = $pkg?->expiry_date ?? $sterilization->expiry_date;

                    StorageBmhp::create([
                        'sterilization_bmhp_id' => $sterilization->id,
                        'packaging_bmhp_id' => $pkgItem->packaging_bmhp_id,
                        'packaging_bmhp_item_id' => $pkgItem->id,
                        'production_bmhp_item_id' => $prodItem?->id ?? $pkgItem->production_bmhp_item_id,
                        'barcode_no' => $pkgItem->barcode_no,
                        'rack_code' => $rackCode,
                        'expiry_date' => $expiry,
                        'status' => StorageBmhp::STATUS_TERSIMPAN,
                        'stored_at' => now(),
                        'created_by' => $actor,
                    ]);

                    $alreadyStored[] = $itemId;
                }
            });

            return $this->success('Unit BMHP berhasil disimpan ke Gudang Steril BMHP.', $this->incomingPayload($sterilization->refresh()));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Daftar inventaris BMHP di rak gudang steril.
     */
    public function inventory(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));
        $search = $request->input('search');

        $query = StorageBmhp::with([
            'sterilization.machine',
            'packaging.packagingType',
            'packaging.production',
            'productionItem',
        ])
            ->sterilePool()
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('rack_code', 'like', "%{$s}%")
                        ->orWhere('barcode_no', 'like', "%{$s}%")
                        ->orWhereHas('productionItem', fn ($p) => $p->where('name', 'like', "%{$s}%")
                            ->orWhere('package_name', 'like', "%{$s}%"));
                });
            })
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(20);

        $today = now()->startOfDay();

        $query->getCollection()->transform(function (StorageBmhp $row) use ($today, $days) {
            $expiry = $row->expiry_date ? Carbon::parse($row->expiry_date)->startOfDay() : null;
            $daysToExpiry = $expiry ? (int) $today->diffInDays($expiry, false) : null;
            $isExpired = $expiry ? $expiry->lt($today) : false;
            $isAlert = $expiry ? ($daysToExpiry !== null && $daysToExpiry >= 0 && $daysToExpiry <= $days) : false;
            $canDistribute = ! $isExpired && $expiry !== null;
            $blockedReason = null;
            if ($isExpired) {
                $blockedReason = 'Kedaluwarsa';
            } elseif (! $expiry) {
                $blockedReason = 'Tanpa tanggal kedaluwarsa';
            }

            $pi = $row->productionItem;

            return [
                'id' => $row->id,
                'rack_code' => $row->rack_code,
                'stored_at' => $row->stored_at?->toISOString(),
                'expiry_date' => $row->expiry_date?->toDateString(),
                'days_to_expiry' => $daysToExpiry,
                'alert' => $isAlert,
                'expired' => $isExpired,
                'can_distribute' => $canDistribute,
                'blocked_reason' => $blockedReason,
                'source' => $pi?->source ?? 'satuan',
                'package_name' => $pi?->package_name,
                'package_no' => $pi?->package_no,
                'barcode_no' => $row->barcode_no,
                'production_code' => $row->packaging?->production?->code,
                // Dikelompokkan per kemasan — batch sterilisasi tidak punya kode sendiri.
                'batch' => $row->packaging ? $row->packaging->prefix.$row->packaging->code : '-',
                'unit' => [
                    'id' => $row->packaging_bmhp_item_id,
                    'code' => $row->barcode_no,
                    'instrument' => $pi?->name ?? 'BMHP',
                    'quantity' => $pi?->quantity ?? 1,
                    'unit_name' => $pi?->unit_name ?? 'pcs',
                    'image_url' => null,
                ],
                'order' => null,
            ];
        });

        return $this->success('Inventaris gudang steril BMHP berhasil diambil.', $query);
    }

    /**
     * Ringkasan statistik angka gudang steril BMHP.
     */
    public function summary(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($days);

        $rows = StorageBmhp::sterilePool()->get(['id', 'expiry_date']);

        $total = $rows->count();
        $alert = $rows->filter(fn ($s) => $s->expiry_date
            && Carbon::parse($s->expiry_date)->startOfDay()->gte($today)
            && Carbon::parse($s->expiry_date)->startOfDay()->lte($limit))->count();
        $expired = $rows->filter(fn ($s) => $s->expiry_date && Carbon::parse($s->expiry_date)->startOfDay()->lt($today))->count();
        $noExpiry = $rows->filter(fn ($s) => ! $s->expiry_date)->count();

        return $this->success('Ringkasan gudang steril BMHP berhasil diambil.', [
            'total' => $total,
            'alert' => $alert,
            'expired' => $expired,
            'no_expiry' => $noExpiry,
        ]);
    }

    /**
     * Opsi order BMHP steril (satuan & paket) beserta stok steril yang tersedia di rak Gudang Steril BMHP.
     */
    public function orderOptions(): JsonResponse
    {
        $today = now()->startOfDay();

        // Hitung stok steril BMHP SATUAN di rak Gudang Steril BMHP
        $satuanRows = StorageBmhp::sterilePool()
            ->where('status', StorageBmhp::STATUS_TERSIMPAN)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            })
            ->whereHas('productionItem', fn ($p) => $p->where('source', 'satuan'))
            ->with('productionItem.bmhp')
            ->get();

        $satuanCounts = [];
        foreach ($satuanRows as $row) {
            $bmhpId = $row->productionItem?->bmhp_id;
            if ($bmhpId) {
                $satuanCounts[$bmhpId] = ($satuanCounts[$bmhpId] ?? 0) + 1;
            }
        }

        $allBmhps = Bmhp::whereNull('deleted_by')->orderBy('name')->get();
        $satuanResult = [];
        foreach ($allBmhps as $b) {
            $count = $satuanCounts[$b->id] ?? 0;
            $satuanResult[] = [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
                'unit' => $b->unit,
                'available_stocks_count' => $count,
                'available_sterile_count' => $count,
            ];
        }

        // Hitung stok steril BMHP PAKET di rak Gudang Steril BMHP
        $paketRows = StorageBmhp::sterilePool()
            ->where('status', StorageBmhp::STATUS_TERSIMPAN)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            })
            ->whereHas('productionItem', fn ($p) => $p->where('source', 'paket'))
            ->with('productionItem.package.items.bmhp')
            ->get();

        $paketCounts = [];
        foreach ($paketRows as $row) {
            $pkgId = $row->productionItem?->package_bmhp_id;
            if ($pkgId) {
                $paketCounts[$pkgId] = ($paketCounts[$pkgId] ?? 0) + 1;
            }
        }

        $allPackages = PackageBmhp::with('items.bmhp')->whereNull('deleted_by')->orderBy('name')->get();
        $paketResult = [];
        foreach ($allPackages as $p) {
            $count = $paketCounts[$p->id] ?? 0;
            $items = $p->items->map(fn ($it) => [
                'instrument_id' => $it->bmhp_id,
                'quantity' => (int) $it->qty,
                'instrument' => [
                    'id' => $it->bmhp_id,
                    'code' => $it->bmhp?->code ?? '',
                    'name' => $it->bmhp?->name ?? '',
                ],
            ])->values()->all();

            $paketResult[] = [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'items_count' => count($items),
                'available_sets' => $count,
                'available_sterile_sets' => $count,
                'items' => $items,
            ];
        }

        return $this->success('Opsi order BMHP steril berhasil diambil.', [
            'instruments' => $satuanResult,
            'catalogs' => $paketResult,
        ]);
    }
}
