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
use Illuminate\Validation\Rule;

/**
 * Controller Gudang Steril BMHP:
 * 1. Antrean BMHP steril yang siap disimpan ke rak (incoming)
 * 2. Simpan BMHP steril ke rak penyimpanan (store)
 * 3. Daftar inventaris BMHP di rak steril (inventory)
 * 4. Opsi order BMHP steril (orderOptions)
 */
class StorageBmhpController extends Controller
{
    private const EXPIRY_ALERT_DAYS = 7;

    /** Relasi batch masuk — hanya kolom yang dipakai payload incoming. */
    private const INCOMING_RELATIONS = [
        'items:id,sterilization_bmhp_id,packaging_bmhp_id,result',
        'items.packaging:id,disabled',
        'items.packaging.items:id,packaging_bmhp_id,production_bmhp_item_id,barcode_no',
        'items.packaging.items.productionItem:id,source,name,package_name,quantity,unit_name,price',
    ];

    /**
     * Batch BMHP steril yang perlu disimpan ke rak gudang steril.
     */
    public function incoming(Request $request): JsonResponse
    {
        $search = $request->input('search');

        $query = SterilizationBmhp::with(self::INCOMING_RELATIONS)
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

        // Baris rak yang sudah tercatat untuk SEMUA batch di halaman ini — satu query,
        // bukan satu query per batch.
        $stored = $this->storedByBatch($query->getCollection()->pluck('id')->all());

        $query->getCollection()->transform(
            fn (SterilizationBmhp $b) => $this->incomingPayload($b, $stored->get($b->id, collect()))
        );

        return $this->success('Data batch BMHP steril siap disimpan berhasil diambil.', $query);
    }

    /**
     * Baris `storage_bmhp` per batch sterilisasi: [sterilization_bmhp_id => [packaging_bmhp_item_id => row]].
     */
    private function storedByBatch(array $sterilizationIds)
    {
        return StorageBmhp::with('rack:id,name')
            ->whereIn('sterilization_bmhp_id', $sterilizationIds)
            ->get(['sterilization_bmhp_id', 'packaging_bmhp_item_id', 'rack_id'])
            ->groupBy('sterilization_bmhp_id')
            ->map(fn ($rows) => $rows->keyBy('packaging_bmhp_item_id'));
    }

    /**
     * Format payload batch incoming BMHP — hanya field yang dipakai halaman Gudang
     * Steril BMHP. Relasi INCOMING_RELATIONS harus sudah dimuat.
     */
    private function incomingPayload(SterilizationBmhp $sterilization, $storedRecords): array
    {
        $unitRows = collect();
        $seenPackagings = [];

        foreach ($sterilization->items as $sterilItem) {
            // Hanya kemasan yang hasil sterilisasinya sukses / steril
            if ($sterilItem->result && $sterilItem->result !== 'steril') {
                continue;
            }

            $pkg = $sterilItem->packaging;
            if (! $pkg || $pkg->disabled) {
                continue;
            }

            // Item sterilisasi dibuat per LABEL, jadi kemasan paket ber-N set muncul
            // N kali — isinya cukup diambil sekali.
            if (isset($seenPackagings[$pkg->id])) {
                continue;
            }
            $seenPackagings[$pkg->id] = true;

            foreach ($pkg->items as $it) {
                $pi = $it->productionItem;
                $storedRow = $storedRecords->get($it->id);
                $quantity = $pi?->quantity ?? 1;
                // Harga satuan saat produksi (snapshot); paket = harga 1 paket.
                $price = (float) ($pi?->price ?? 0);

                $unitRows->push([
                    'id' => $it->id, // packaging_bmhp_item_id
                    'code' => $it->barcode_no,
                    'barcode_no' => $it->barcode_no,
                    'instrument' => $pi?->name ?? 'BMHP',
                    'source' => $pi?->source ?? 'satuan',
                    'package_name' => $pi?->package_name,
                    'quantity' => $quantity,
                    'unit_name' => $pi?->unit_name ?? 'pcs',
                    'price' => $price,
                    'total_price' => $price * $quantity,
                    'stored' => $storedRow !== null,
                    'rack_id' => $storedRow?->rack_id,
                    'rack_name' => $storedRow?->rack?->name,
                ]);
            }
        }

        $unitCount = $unitRows->count();
        $storedCount = $unitRows->where('stored', true)->count();

        return [
            'id' => $sterilization->id,
            // Kode siklus sterilisasi, mis. AUT01-191026-03.
            'sterilization_code' => $sterilization->code,
            'processed_at' => $sterilization->sterilized_at?->toISOString() ?? $sterilization->created_at?->toISOString(),
            'expiry_date' => $sterilization->expiry_date?->toDateString(),
            'unit_count' => $unitCount,
            'stored_count' => $storedCount,
            'units' => $unitRows->values()->all(),
        ];
    }

    /**
     * Simpan unit-unit BMHP ke rak gudang steril BMHP.
     * Request body items: [{ packaging_item_id, rack_id }]
     */
    public function store(Request $request, SterilizationBmhp $sterilization): JsonResponse
    {
        if ($sterilization->status !== SterilizationBmhp::STATUS_SELESAI) {
            return $this->error('Batch ini belum steril / tidak siap disimpan.', 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.packaging_item_id' => 'required|integer|exists:packaging_bmhp_item,id',
            // Rak dari master `racks` yang masih aktif.
            'items.*.rack_id' => ['required', 'integer', Rule::exists('racks', 'id')->whereNull('deleted_by')],
        ], [
            'items.*.rack_id.exists' => 'Rak tidak ditemukan di master rak.',
        ]);

        $itemIds = collect($validated['items'])->pluck('packaging_item_id')->all();

        // Cari item yang valid milik batch sterilisasi ini
        $validItems = PackagingBmhpItem::whereIn('id', $itemIds)
            ->whereHas('packaging.sterilizationItems', function ($q) use ($sterilization) {
                $q->where('sterilization_bmhp_id', $sterilization->id)
                    ->where(fn ($w) => $w->whereNull('result')->orWhere('result', 'steril'));
            })
            ->get()
            ->keyBy('id');

        // `deleted_by IS NULL` sudah dari global scope HasAuditColumns.
        $alreadyStored = StorageBmhp::where('sterilization_bmhp_id', $sterilization->id)
            ->pluck('packaging_bmhp_item_id')
            ->all();

        try {
            DB::transaction(function () use ($validated, $sterilization, $validItems, &$alreadyStored) {
                $actor = auth()->user()?->name ?? 'Petugas';

                foreach ($validated['items'] as $item) {
                    $itemId = (int) $item['packaging_item_id'];

                    if (! $validItems->has($itemId) || in_array($itemId, $alreadyStored, true)) {
                        continue;
                    }

                    $pkgItem = $validItems->get($itemId);

                    // Kedaluwarsa tidak disalin — dibaca dari kemasannya (packaging_bmhp.expiry_date).
                    StorageBmhp::create([
                        'sterilization_bmhp_id' => $sterilization->id,
                        'packaging_bmhp_id' => $pkgItem->packaging_bmhp_id,
                        'packaging_bmhp_item_id' => $pkgItem->id,
                        'production_bmhp_item_id' => $pkgItem->production_bmhp_item_id,
                        'barcode_no' => $pkgItem->barcode_no,
                        'rack_id' => (int) $item['rack_id'],
                        'stored_at' => now(),
                        'created_by' => $actor,
                    ]);

                    $alreadyStored[] = $itemId;
                }
            });

            // Relasinya dimuat sekaligus — tanpa ini payload memicu satu query per
            // kemasan/label (lazy loading).
            $sterilization->refresh()->load(self::INCOMING_RELATIONS);

            return $this->success(
                'Unit BMHP berhasil disimpan ke Gudang Steril BMHP.',
                $this->incomingPayload($sterilization, $this->storedByBatch([$sterilization->id])->get($sterilization->id, collect()))
            );
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

        // Relasi dimuat per halaman (20 baris), hanya kolom yang dipakai tabel.
        // Kedaluwarsa = milik kemasannya (packaging_bmhp.expiry_date).
        $query = StorageBmhp::with([
            'rack:id,name',
            'packaging:id,expiry_date',
            'sterilization:id,code',
            'productionItem:id,source,name,package_name,quantity,unit_name,price',
        ])
            ->sterilePool()
            // Filter rak (`?rack_id=`).
            ->when($request->input('rack_id'), fn ($q, $rackId) => $q->where('rack_id', $rackId))
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('barcode_no', 'like', "%{$s}%")
                        ->orWhereHas('rack', fn ($r) => $r->where('name', 'like', "%{$s}%"))
                        ->orWhereHas('productionItem', fn ($p) => $p->where('name', 'like', "%{$s}%")
                            ->orWhere('package_name', 'like', "%{$s}%"));
                });
            })
            // Kedaluwarsa terdekat dulu; tanpa tanggal di akhir.
            ->orderByRaw(
                '(SELECT expiry_date FROM packaging_bmhp WHERE packaging_bmhp.id = storage_bmhp.packaging_bmhp_id) IS NULL, '
                .'(SELECT expiry_date FROM packaging_bmhp WHERE packaging_bmhp.id = storage_bmhp.packaging_bmhp_id) ASC'
            )
            ->paginate(20);

        $today = now()->startOfDay();

        $query->getCollection()->transform(function (StorageBmhp $row) use ($today, $days) {
            $expiryDate = $row->packaging?->expiry_date;
            $expiry = $expiryDate ? Carbon::parse($expiryDate)->startOfDay() : null;
            $daysToExpiry = $expiry ? (int) $today->diffInDays($expiry, false) : null;
            $isExpired = $expiry ? $expiry->lt($today) : false;
            $isAlert = $expiry ? ($daysToExpiry !== null && $daysToExpiry >= 0 && $daysToExpiry <= $days) : false;

            $pi = $row->productionItem;
            $quantity = $pi?->quantity ?? 1;
            // Harga satuan saat produksi (production_bmhp_item.price); paket = harga 1 paket.
            $price = (float) ($pi?->price ?? 0);

            return [
                'id' => $row->id,
                'rack_id' => $row->rack_id,
                'rack_name' => $row->rack?->name,
                'stored_at' => $row->stored_at?->toISOString(),
                'expiry_date' => $expiry?->toDateString(),
                'days_to_expiry' => $daysToExpiry,
                'alert' => $isAlert,
                'expired' => $isExpired,
                'source' => $pi?->source ?? 'satuan',
                'package_name' => $pi?->package_name,
                'barcode_no' => $row->barcode_no,
                // Kode siklus sterilisasi tempat BMHP ini disteril, mis. AUT01-191026-03.
                'sterilization_code' => $row->sterilization?->code,
                'unit' => [
                    'instrument' => $pi?->name ?? 'BMHP',
                    'quantity' => $quantity,
                    'unit_name' => $pi?->unit_name ?? 'pcs',
                    'price' => $price,
                    'total_price' => $price * $quantity,
                ],
            ];
        });

        return $this->success('Inventaris gudang steril BMHP berhasil diambil.', $query);
    }

    /**
     * Opsi order BMHP steril (satuan & paket) beserta stok steril yang tersedia di rak Gudang Steril BMHP.
     */
    public function orderOptions(): JsonResponse
    {
        $today = now()->startOfDay();

        // Hitung stok steril BMHP SATUAN di rak Gudang Steril BMHP
        $satuanRows = StorageBmhp::sterilePool()
            ->usableOn($today)
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
            ->usableOn($today)
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
