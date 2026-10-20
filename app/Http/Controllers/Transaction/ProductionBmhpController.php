<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Bmhp;
use App\Models\PackageBmhp;
use App\Models\PackagingBmhp;
use App\Models\PackagingType;
use App\Models\ProductionBmhp;
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
    /** Tanggal kemasan siap steril untuk filter & urutan tab Sterilisasi. */
    private const READY_DATE = 'COALESCE(packaged_at, created_at)';

    /** Panjang rentang tanggal maksimal (hari, inklusif) di tab Sterilisasi. */
    private const STERIL_MAX_DAYS = 3;

    /** Kolom item produksi yang dipakai kartu, isi kemasan & label (tanpa harga dll). */
    private const PRODUCTION_ITEM_COLUMNS = 'id,source,name,package_no,quantity,unit_name';

    /**
     * Data kandidat untuk form produksi baru:
     * - Daftar BMHP satuan (aktif)
     * - Daftar Paket BMHP (beserta isi itemnya)
     *
     * Jenis kemasan & mesin sterilisator tidak ikut di sini — tab Pengemasan &
     * Sterilisasi mengambilnya sendiri lewat endpoint `options` saat modalnya dibuka.
     */
    public function candidates(): JsonResponse
    {
        // `deleted_by IS NULL` sudah dari global scope HasAuditColumns.
        $bmhps = Bmhp::orderBy('name')->get();

        $packages = PackageBmhp::with(['items.bmhp.unitMaster'])
            ->orderBy('name')
            ->get();

        return $this->success('Kandidat produksi BMHP.', [
            'bmhps' => $bmhps,
            'packages' => $packages,
        ]);
    }

    /**
     * Buat batch produksi baru BMHP:
     * - Validasi stok cukup di `mst_bmhp`
     * - Potong `stock_qty`
     * - Per BMHP / paket: buat `production_bmhp` & `production_bmhp_item`, lalu
     *   langsung `packaging_bmhp` (masuk antrean Inspeksi & Pengemasan). Kode
     *   produksi = kemasan = label: BMHP-{kode master}-{yymmdd}{urut}
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

                // 3. Satu produksi + satu kemasan per BMHP / paket — baris yang
                //    merujuk master yang sama digabung jumlahnya. Kodenya diambil
                //    dari `code` master BMHP / Paket BMHP dan dipakai sama persis
                //    oleh produksi, kemasan & label: BMHP-KS-26101901.
                $groups = [];
                foreach ($validated['items'] as $item) {
                    $key = $item['type'].':'.(int) $item['ref_id'];
                    $groups[$key] ??= ['type' => $item['type'], 'ref_id' => (int) $item['ref_id'], 'quantity' => 0];
                    $groups[$key]['quantity'] += (float) $item['quantity'];
                }

                // 4. Buat produksi & kemasannya (langsung masuk tahap pengemasan)
                //    beserta item produksi & label-labelnya
                $productions = [];
                foreach ($groups as $item) {
                    $qty = $item['quantity'];
                    $master = $item['type'] === 'satuan'
                        ? $bmhpRecords->get($item['ref_id'])
                        : $packageModels[$item['ref_id']];

                    // Nomor urut = urutan kemasan BMHP hari itu (lintas BMHP), jadi
                    // prefix + nomor itu juga unik untuk kode produksi.
                    $prefix = 'BMHP-'.$master->code.'-';
                    $code = PackagingBmhp::nextCode();

                    $production = ProductionBmhp::create([
                        'code' => $prefix.$code,
                        'note' => $validated['note'] ?? null,
                    ]);
                    $productions[] = $production;

                    $packaging = PackagingBmhp::create([
                        'production_bmhp_id' => $production->id,
                        'prefix' => $prefix,
                        'code' => $code,
                        'note' => $validated['note'] ?? null,
                    ]);

                    if ($item['type'] === 'satuan') {
                        $bmhp = $master;
                        $prodItem = $production->items()->create([
                            'source' => 'satuan',
                            'bmhp_id' => $bmhp->id,
                            'name' => $bmhp->name,
                            'quantity' => $qty,
                            'unit_name' => $bmhp->unit,
                            // Harga satuan saat itu — tidak ikut berubah bila harga master diubah.
                            'price' => $bmhp->price,
                        ]);

                        // Label kemasan satuan
                        $packaging->items()->create([
                            'production_bmhp_item_id' => $prodItem->id,
                            'barcode_no' => $packaging->barcodeNoFor(null),
                        ]);
                    } else {
                        $pkg = $master;
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
                                // Harga 1 paket saat itu (total isi paket × harga BMHP-nya).
                                'price' => $pkg->total_price,
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
                    'productions' => collect($productions)->each->load(['items', 'packagings.items'])->values(),
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
     * Daftar antrean Inspeksi & Pengemasan BMHP — paginasi 20, dimuat bertahap
     * (lazy load) oleh frontend saat daftar di-scroll. Kemasan yang di-void
     * (disabled, mis. gagal steril) tetap ditampilkan sebagai riwayat.
     */
    public function packagingQueue(Request $request): JsonResponse
    {
        $search = $request->input('search');

        // Relasi dimuat per halaman (20 baris), hanya kolom yang dipakai kartu & label.
        $query = PackagingBmhp::with([
            'packagingType:id,name,shelf_life_days',
            'items:id,packaging_bmhp_id,production_bmhp_item_id,barcode_no',
            'items.productionItem:'.self::PRODUCTION_ITEM_COLUMNS,
        ])
            ->withStageFlags()
            ->when($search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->whereRaw('CONCAT(prefix, code) LIKE ?', ["%{$s}%"])
                        ->orWhereHas('items.productionItem', fn ($pi) => $pi->where('name', 'like', "%{$s}%"));
                });
            });

        $query = $this->applyDateRange($query, $request, 'created_at');

        // Filter tahap (`?stage=`), dibaca dari kolom — sama dengan PackagingBmhp::stage().
        $steril = fn ($q) => $q->where('result', SterilizationBmhpItem::RESULT_STERIL);
        match ($request->input('stage')) {
            'belum' => $query->whereNull('completed_at')->where('disabled', false),
            'proses' => $query->whereNotNull('completed_at')->where('disabled', false)
                ->whereDoesntHave('sterilizationItems', $steril),
            'steril' => $query->where('disabled', false)->whereHas('sterilizationItems', $steril),
            'gagal' => $query->where('disabled', true),
            default => null,
        };

        // Belum dikemas (`completed_at` kosong) di atas, lalu yang terbaru dulu.
        $data = $query
            ->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        // `stage` = tahap kemasan (lihat PackagingBmhp::stage()) untuk warna kartu;
        // penanda EXISTS-nya tidak perlu ikut dikirim.
        $data->getCollection()->each(fn (PackagingBmhp $p) => $p
            ->setAttribute('stage', $p->stage())
            ->makeHidden(['in_sterilization', 'is_sterilized']));

        return $this->success('Antrean pengemasan BMHP.', $data);
    }

    /**
     * Selesaikan Inspeksi & Pengemasan BMHP dan cetak label barcode
     */
    public function packagingComplete(Request $request, PackagingBmhp $packaging): JsonResponse
    {
        if ($packaging->completed_at !== null) {
            return $this->error('Batch pengemasan ini sudah diselesaikan sebelumnya.', 422);
        }
        if ($packaging->disabled) {
            return $this->error('Kemasan ini sudah di-void.', 422);
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
                    'completed_by' => $actor,
                    'completed_at' => now(),
                ]);
            });

            // Untuk modal cetak label — kode produksi tidak dipakai lagi di sana.
            $packaging->refresh()->load([
                'packagingType:id,name,shelf_life_days',
                'items:id,packaging_bmhp_id,production_bmhp_item_id,barcode_no',
                'items.productionItem:'.self::PRODUCTION_ITEM_COLUMNS,
            ]);

            // Label payload untuk modal cetak
            $labels = [];
            foreach ($packaging->items as $it) {
                $pi = $it->productionItem;
                $labels[] = [
                    'label_code' => $packaging->prefix.$packaging->code.($pi?->package_no ? ' '.$pi->package_no : ''),
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
     * Satu daftar tab Sterilisasi — paginasi 10 (nomor halaman), urut per tahap:
     * 1. `belum`    : kemasan siap steril yang belum masuk batch (kind = packaging)
     * 2. `menunggu` : batch sterilisasi yang menunggu validasi (kind = batch)
     * 3. `sudah`    : batch yang sudah divalidasi, steril/gagal (kind = batch)
     * Di dalam tiap tahap, yang terbaru dulu. `?status=` menyaring satu tahap saja.
     */
    public function sterilizationFeed(Request $request): JsonResponse
    {
        $this->validateSterilizationRange($request);
        $status = $request->input('status');
        $parts = [];

        if (! $status || $status === 'belum') {
            $parts[] = $this->readyQuery($request)
                ->selectRaw("'packaging' as kind, id, 0 as stage, ".self::READY_DATE.' as sort_at')
                ->toBase();
        }

        if (! $status || in_array($status, ['menunggu', 'sudah'], true)) {
            $parts[] = $this->batchQuery($request)
                ->when($status === 'menunggu', fn ($q) => $q->where('status', SterilizationBmhp::STATUS_DIPROSES))
                ->when($status === 'sudah', fn ($q) => $q->where('status', '!=', SterilizationBmhp::STATUS_DIPROSES))
                ->selectRaw(
                    "'batch' as kind, id, CASE WHEN status = ? THEN 1 ELSE 2 END as stage, COALESCE(sterilized_at, created_at) as sort_at",
                    [SterilizationBmhp::STATUS_DIPROSES]
                )
                ->toBase();
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $page = DB::query()->fromSub($union, 'feed')
            ->orderBy('stage')
            ->orderByDesc('sort_at')
            ->orderByDesc('id')
            ->paginate(10);

        // Hidrasi baris union jadi model lengkap, tetap dalam urutan feed — hanya
        // untuk id di halaman ini, dan dilewati bila halaman ini tak memuat jenisnya.
        $rows = collect($page->items());
        $packagingIds = $rows->where('kind', 'packaging')->pluck('id');
        $batchIds = $rows->where('kind', 'batch')->pluck('id');
        $itemColumns = 'items:id,packaging_bmhp_id,production_bmhp_item_id,barcode_no';

        $packagings = $packagingIds->isEmpty() ? collect() : PackagingBmhp::with([
            'production:id,code',
            'packagingType:id,name',
            $itemColumns,
            'items.productionItem:'.self::PRODUCTION_ITEM_COLUMNS,
        ])
            ->whereIn('id', $packagingIds)
            ->get()
            ->keyBy('id');

        $batches = $batchIds->isEmpty() ? collect() : SterilizationBmhp::with([
            'items:id,sterilization_bmhp_id,packaging_bmhp_id,packaging_barcode,result',
            'items.packaging:id,prefix,code,packaging_type_id,disabled,disabled_by',
            'items.packaging.packagingType:id,name',
            'items.packaging.'.$itemColumns,
            'items.packaging.items.productionItem:'.self::PRODUCTION_ITEM_COLUMNS,
        ])
            ->whereIn('id', $batchIds)
            ->get()
            ->keyBy('id');

        $page->setCollection($rows->map(fn ($row) => [
            'kind' => $row->kind,
            'status' => ['belum', 'menunggu', 'sudah'][(int) $row->stage],
            'packaging' => $row->kind === 'packaging' ? $packagings->get($row->id) : null,
            'batch' => $row->kind === 'batch' ? $batches->get($row->id) : null,
        ]));

        return $this->success('Daftar sterilisasi BMHP.', $page);
    }

    /**
     * Jumlah tiap tahap tab Sterilisasi (chip filter), dengan search yang sama —
     * angkanya tetap benar walau daftarnya baru dimuat sebagian.
     */
    public function sterilizationSummary(Request $request): JsonResponse
    {
        $this->validateSterilizationRange($request);

        // Menunggu & sudah dihitung sekali jalan dari tabel batch.
        $batch = $this->batchQuery($request)
            ->toBase()
            ->selectRaw('COUNT(*) as total, SUM(status = ?) as menunggu', [SterilizationBmhp::STATUS_DIPROSES])
            ->first();
        $menunggu = (int) $batch->menunggu;

        return $this->success('Ringkasan sterilisasi BMHP.', [
            'belum' => $this->readyQuery($request)->count(),
            'menunggu' => $menunggu,
            'sudah' => (int) $batch->total - $menunggu,
        ]);
    }

    /**
     * Gagal steril: kemasan lama di-void — rincian lengkapnya (kemasan lama, siklus
     * steril & alasan gagalnya, kemasan penggantinya) disimpan sebagai JSON di
     * `disabled_reason`, catatannya tidak diubah — lalu tiap kemasan diganti kemasan BARU
     * dari produksi yang sama — kembali ke antrean Inspeksi & Pengemasan (stok tidak
     * dipotong lagi). Prefix kemasan baru diberi awalan "GS-" (mis. GS-BMHP-KS-),
     * sekali saja walau gagal berulang. Nomornya baru karena `code` kemasan unik,
     * label lama tetap milik kemasan yang di-void.
     */
    private function repackFailed($packagingIds, SterilizationBmhp $sterilization, string $actor): void
    {
        $packagings = PackagingBmhp::with('items.productionItem')->whereIn('id', $packagingIds)->get();

        foreach ($packagings as $old) {
            $prefix = str_starts_with($old->prefix, 'GS-') ? $old->prefix : 'GS-'.$old->prefix;

            $new = PackagingBmhp::create([
                'production_bmhp_id' => $old->production_bmhp_id,
                'prefix' => $prefix,
            ]);

            $old->update([
                'disabled' => true,
                'disabled_at' => now(),
                'disabled_by' => $actor,
                'disabled_reason' => [
                    'reason' => PackagingBmhp::DISABLED_GAGAL_STERIL,
                    'packaging' => ['id' => $old->id, 'code' => $old->full_code],
                    'sterilization' => [
                        'id' => $sterilization->id,
                        'code' => $sterilization->code,
                        'failure_reason' => $sterilization->failure_reason,
                        'failure_note' => $sterilization->failure_note,
                    ],
                    'replaced_by' => ['id' => $new->id, 'code' => $new->full_code],
                    'by' => $actor,
                    'at' => now()->toIso8601String(),
                ],
            ]);

            foreach ($old->items as $item) {
                $new->items()->create([
                    'production_bmhp_item_id' => $item->production_bmhp_item_id,
                    'barcode_no' => $new->barcodeNoFor($item->productionItem?->package_no),
                ]);
            }
        }
    }

    /**
     * Rentang tanggal tab Sterilisasi maksimal STERIL_MAX_DAYS hari (inklusif).
     * Gagal → ValidationException (422) lewat handler global.
     */
    private function validateSterilizationRange(Request $request): void
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    if (! $request->date_from || ! $value) {
                        return;
                    }
                    $days = Carbon::parse($request->date_from)->startOfDay()
                        ->diffInDays(Carbon::parse($value)->startOfDay()) + 1;
                    if ($days > self::STERIL_MAX_DAYS) {
                        $fail('Rentang tanggal maksimal '.self::STERIL_MAX_DAYS.' hari.');
                    }
                },
            ],
        ]);
    }

    /**
     * Kemasan selesai dikemas yang belum masuk siklus sterilisasi aktif.
     */
    private function readyQuery(Request $request)
    {
        $batchedPackagingIds = SterilizationBmhpItem::select('packaging_bmhp_id');

        $query = PackagingBmhp::whereNotNull('completed_at')
            ->where('disabled', false)
            ->whereNotIn('id', $batchedPackagingIds)
            ->when($request->input('search'), function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->whereRaw('CONCAT(prefix, code) LIKE ?', ["%{$s}%"])
                        ->orWhereHas('items', fn ($it) => $it->where('barcode_no', 'like', "%{$s}%"))
                        ->orWhereHas('items.productionItem', fn ($pi) => $pi->where('name', 'like', "%{$s}%"));
                });
            });

        // Tanggal kemasan = saat selesai dikemas; sama dengan urutan di feed.
        return $this->applyDateRange($query, $request, self::READY_DATE);
    }

    private function batchQuery(Request $request)
    {
        $query = SterilizationBmhp::when($request->input('search'), function ($q, $s) {
            // Kode batch, atau barcode/kode kemasan di dalamnya.
            $q->where(function ($w) use ($s) {
                $w->where('code', 'like', "%{$s}%")
                    ->orWhere('machine_name', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%")
                    ->orWhereHas('items', fn ($it) => $it->where('packaging_barcode', 'like', "%{$s}%"));
            });
        });

        return $this->applyDateRange($query, $request, 'COALESCE(sterilized_at, created_at)');
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

        // Hanya kemasan yang sudah dikemas, tidak di-void & belum masuk batch lain.
        $packagingIds = array_values(array_unique($validated['packaging_ids']));
        $readyCount = PackagingBmhp::whereIn('id', $packagingIds)
            ->whereNotNull('completed_at')
            ->where('disabled', false)
            ->whereNotIn('id', SterilizationBmhpItem::select('packaging_bmhp_id'))
            ->count();
        if ($readyCount !== count($packagingIds)) {
            return $this->error('Ada kemasan yang belum dikemas, sudah di-void, atau sudah masuk batch sterilisasi lain.', 422);
        }

        try {
            $sterilization = DB::transaction(function () use ($validated, $packagingIds) {
                $machine = SterilizerMachine::findOrFail($validated['machine_id']);
                $actor = auth()->user()?->name ?? $validated['operator'] ?? 'Petugas';

                $sterilization = SterilizationBmhp::create([
                    'code' => SterilizationBmhp::nextCode($machine->code),
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

                foreach ($packagingIds as $pkgId) {
                    $pkg = PackagingBmhp::with('items')->findOrFail($pkgId);

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

        $indicator = ['nullable', Rule::in([SterilizationBmhp::INDICATOR_LULUS, SterilizationBmhp::INDICATOR_GAGAL])];

        $validated = $request->validate([
            'result' => ['required', Rule::in(['steril', 'gagal'])],
            'chemical_indicator_result' => $indicator,
            'bio_indicator_result' => $indicator,
            'bowie_dick_result' => $indicator,
            'failure_reason' => ['required_if:result,gagal', 'nullable', Rule::in(SterilizationBmhp::FAILURE_REASONS)],
            // Alasan "lainnya" wajib ditulis sendiri.
            'failure_note' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => $request->input('result') === 'gagal' && $request->input('failure_reason') === 'lainnya'
            )],
        ], [
            'failure_reason.required_if' => 'Alasan gagal steril wajib dipilih.',
            'failure_note.required' => 'Tulis alasan gagal steril.',
        ]);

        // Batch tidak boleh dinyatakan steril bila ada indikator yang gagal.
        $failedIndicator = collect(['chemical_indicator_result', 'bio_indicator_result', 'bowie_dick_result'])
            ->contains(fn ($k) => ($validated[$k] ?? null) === SterilizationBmhp::INDICATOR_GAGAL);
        if ($validated['result'] === 'steril' && $failedIndicator) {
            return $this->error('Ada indikator yang gagal — batch tidak bisa dinyatakan steril.', 422);
        }

        try {
            DB::transaction(function () use ($sterilization, $validated) {
                $isSteril = $validated['result'] === 'steril';
                $actor = auth()->user()?->name ?? 'Petugas';

                $packagingIds = $sterilization->items()->pluck('packaging_bmhp_id')->unique();
                $minExpiry = PackagingBmhp::whereIn('id', $packagingIds)->min('expiry_date');

                $sterilization->update([
                    'status' => $isSteril ? SterilizationBmhp::STATUS_SELESAI : SterilizationBmhp::STATUS_GAGAL,
                    'chemical_indicator_result' => $validated['chemical_indicator_result'] ?? null,
                    'bio_indicator_result' => $validated['bio_indicator_result'] ?? null,
                    'bowie_dick_result' => $validated['bowie_dick_result'] ?? null,
                    'failure_reason' => $isSteril ? null : $validated['failure_reason'],
                    'failure_note' => ! $isSteril && $validated['failure_reason'] === 'lainnya'
                        ? $validated['failure_note']
                        : null,
                    'sterilized_at' => now(),
                    'expiry_date' => $sterilization->expiry_date ?? $minExpiry,
                    'completed_at' => now(),
                    'completed_by' => $actor,
                ]);

                $sterilization->items()->update([
                    'result' => $validated['result'],
                ]);

                // Steril: kemasan tidak perlu diubah — hasilnya sudah di `result`
                // item sterilisasi. Gagal: kemasan di-void & dikemas ulang.
                if (! $isSteril) {
                    $this->repackFailed($packagingIds, $sterilization, $actor);
                }
            });

            return $this->success(
                $validated['result'] === 'steril'
                    ? 'Validasi berhasil — BMHP telah dinyatakan STERIL dan siap digunakan.'
                    : 'Validasi selesai — batch dinyatakan GAGAL steril. Kemasan dibuat ulang (GS-) dan kembali ke antrean Inspeksi & Pengemasan.',
                $sterilization->refresh()->load('items', 'machine')
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
