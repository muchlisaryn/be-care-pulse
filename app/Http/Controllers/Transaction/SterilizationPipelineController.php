<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\Packaging;
use App\Models\PackagingItem;
use App\Models\PipelineEvent;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        // PKG siap-steril (belum tergabung ke batch). PKG yang di-void (disabled)
        // karena gagal steril tidak ditampilkan.
        $readyQuery = Packaging::with([...self::CHAIN, 'items'])
            ->where('status', Packaging::STATUS_SELESAI)
            ->where('disabled', false)
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                ->orWhere('washing_code', 'like', "%{$s}%")));

        // Item siap-steril belum punya tanggal sterilisasi — acuannya waktu
        // pengemasan selesai, yaitu saat ia masuk antrean tahap ini.
        // Satu baris = satu LABEL (barcode_no), bukan satu PKG: sebuah PKG bisa
        // terbagi ke beberapa batch steril, jadi label yang sudah masuk batch
        // disaring lewat sterilization_items.packaging_barcode.
        $batchedBarcodes = SterilizationItem::whereNotNull('packaging_barcode')
            ->where('disabled', false)
            ->pluck('packaging_barcode')
            ->unique()
            ->flip();

        $ready = $this->applyDateRange(
            $readyQuery,
            $request,
            'COALESCE(completed_at, packaged_at, created_at)'
        )
            ->orderByDesc('id')
            ->get()
            ->flatMap(fn (Packaging $p) => $this->readyLabels($p, $batchedBarcodes));

        // Batch STR dari pipeline produksi: yang sedang diproses (menunggu validasi)
        // + yang sudah divalidasi (selesai/gagal) sebagai RIWAYAT — agar batch steril
        // tidak hilang dari tampilan setelah divalidasi. FE memisahkan lewat
        // `sterilization.status`.
        $batchQuery = Sterilization::with(['packagings.washing.production.items.instrumentStock.instrument', 'items.instrumentStock.instrument'])
            ->whereIn('status', [Sterilization::STATUS_DIPROSES, Sterilization::STATUS_SELESAI, Sterilization::STATUS_GAGAL])
            ->whereNull('order_id')
            ->when($search, fn ($q, $s) => $q->where('code', 'like', "%{$s}%"));

        // Tanggal acuan batch STR: waktu sterilisasi dijalankan.
        $batches = $this->applyDateRange(
            $batchQuery,
            $request,
            'COALESCE(sterilized_at, created_at)'
        )
            ->orderByDesc('id')
            ->get()
            ->map(fn (Sterilization $b) => $this->batchPayload($b));

        // Unit gagal steril tidak lagi mengendap di antrean steril: unitnya
        // dikembalikan ke tahap Packaging (lihat validateResult) untuk diproses ulang.
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
     * Validasi hasil scan barcode label kemasan sebelum dicentang ke batch steril.
     * Dipisah dari daftar agar barcode yang TIDAK dikenal — atau dikenal tapi tidak
     * layak (sudah masuk batch, di-void, belum selesai dikemas) — bisa dijawab
     * dengan alasan yang jelas, bukan sekadar "tidak ketemu di layar".
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode_no' => 'required|string',
        ]);

        $code = trim($validated['barcode_no']);

        $items = PackagingItem::with('packaging')
            ->where('barcode_no', $code)
            ->where('disabled', false)
            ->get();

        if ($items->isEmpty()) {
            return $this->error("Barcode \"{$code}\" tidak dikenal.", 404);
        }

        $packaging = $items->first()->packaging;

        if (! $packaging || $packaging->disabled) {
            return $this->error("Label \"{$code}\" sudah dibatalkan (kemasannya diproses ulang).", 422);
        }

        if ($packaging->status !== Packaging::STATUS_SELESAI) {
            return $this->error("Kemasan label \"{$code}\" belum selesai dikemas.", 422);
        }

        $batched = SterilizationItem::with('sterilization')
            ->where('packaging_barcode', $code)
            ->where('disabled', false)
            ->first();

        if ($batched) {
            return $this->error(
                "Label \"{$code}\" sudah masuk batch sterilisasi {$batched->sterilization?->code}.",
                422
            );
        }

        $prodByStock = ($packaging->washing?->production?->items ?? collect())
            ->keyBy('instrument_stock_id');
        $first = $items->first();
        $prodFirst = $prodByStock->get($first->instrument_stock_id);
        $isPaket = ($prodFirst?->source ?? $first->source) === 'paket';

        return $this->success('Label ditemukan.', [
            'barcode_no' => $code,
            'name' => $isPaket
                ? ($prodFirst?->package_name ?? $first->package_name ?? 'Paket')
                : ($prodFirst?->name ?? $first->instrumentStock?->instrument?->name ?? 'Instrumen'),
            'packaging_code' => $packaging->full_code,
            'unit_count' => $items->count(),
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
            // Label kemasan terpilih (packaging_item.barcode_no) — satuan pemilihan
            // sekarang label, bukan PKG, sehingga satu PKG bisa terbagi ke beberapa batch.
            'barcode_nos' => 'nullable|array',
            'barcode_nos.*' => 'string',
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

        $barcodes = collect($validated['barcode_nos'] ?? [])->filter()->unique();

        // Isi label terpilih (packaging_item), hanya dari PKG yang sudah selesai
        // dikemas & belum di-void. Label yang sudah masuk batch lain ditolak.
        $labelItems = PackagingItem::whereIn('barcode_no', $barcodes->all())
            ->where('disabled', false)
            ->whereHas('packaging', fn ($q) => $q->where('status', Packaging::STATUS_SELESAI)->where('disabled', false))
            ->whereNotIn('barcode_no', SterilizationItem::whereNotNull('packaging_barcode')
                ->where('disabled', false)
                ->pluck('packaging_barcode'))
            ->get();

        $packagings = Packaging::with(self::CHAIN)
            ->whereIn('id', $labelItems->pluck('packaging_id')->unique()->all())
            ->get();

        // Unit dari label terpilih — hanya yang BELUM dirilis (non-`tersedia`).
        // Filter ini mencegah unit yang SUDAH berhasil steril ikut diproses ulang saat
        // labelnya dibuka lagi untuk pengemasan ulang unit yang gagal.
        $barcodeByStock = $labelItems->pluck('barcode_no', 'instrument_stock_id');

        $stockIds = InstrumentStock::withoutGlobalScopes()
            ->whereIn('id', $labelItems->pluck('instrument_stock_id')->filter()->unique()->all())
            ->where('status', '!=', InstrumentStock::STATUS_TERSEDIA)
            ->pluck('id');

        if ($stockIds->isEmpty()) {
            return $this->error('Tidak ada unit siap-steril yang valid dipilih.', 422);
        }

        // Tgl kedaluwarsa steril diwarisi dari tray packaging (ditetapkan operator
        // saat pengemasan) agar tanggal di label sama dengan yang dipakai gudang.
        // Bila batch menggabungkan beberapa PKG dengan tanggal berbeda, ambil yang
        // PALING AWAL — batch hanya seaman tray yang paling cepat kedaluwarsa.
        // Terakhir pakai default = tgl sterilisasi + masa simpan steril default.
        $expiryDate = $validated['expiry_date']
            ?? $packagings->pluck('expiry_date')->filter()->min()?->toDateString()
            ?? Carbon::parse($validated['sterilized_at'])
                ->addDays(Sterilization::STERILE_SHELF_LIFE_DAYS)
                ->toDateString();

        try {
            $sterilization = DB::transaction(function () use ($validated, $packagings, $stockIds, $expiryDate, $barcodeByStock) {
                $sterilization = Sterilization::create([
                    ...collect($validated)->except(['barcode_nos', 'reproc_stock_ids'])->all(),
                    'method' => $validated['method'] ?? Sterilization::METHOD_UAP,
                    'expiry_date' => $expiryDate,
                    'status' => Sterilization::STATUS_DIPROSES,
                ]);

                foreach ($stockIds as $stockId) {
                    $sterilization->items()->create([
                        'instrument_stock_id' => $stockId,
                        // Label asal unit — penentu kemasan mana yang harus diulang
                        // bila unit ini nanti gagal steril.
                        'packaging_barcode' => $barcodeByStock[$stockId] ?? null,
                    ]);
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

                $sumber = $packagings->isNotEmpty()
                    ? $packagings->count().' packaging ('.$packagings->pluck('code')->implode(', ').')'
                    : $stockIds->count().' unit';

                PipelineEvent::record(PipelineEvent::STAGE_STERILIZATION, $sterilization->code, PipelineEvent::ACTION_DIBUAT, [
                    'note' => 'Batch sterilisasi dibuat dari '.$sumber,
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
                    // Unit gagal: item di-void (disabled) — unitnya dikembalikan ke packaging,
                    // record tetap tersimpan untuk pelacakan.
                    if ($isFailed) {
                        $item->disabled = true;
                        $item->disabled_at = now();
                    }
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
                    $sterilization->expiry_date = $sterilization->computeExpiryDate();
                }
                $sterilization->save();

                // Unit berhasil → steril & siap rilis.
                if ($passed) {
                    InstrumentStock::transitionMany($passed, InstrumentStock::STATUS_TERSEDIA, [
                        'context' => 'sterilization',
                        'reference' => $sterilization->code,
                    ]);
                }

                // Unit gagal → DIKEMBALIKAN ke tahap Packaging untuk diproses ulang.
                // PKG lama yang memuat unit gagal ditandai `disabled` (di-void, hilang
                // dari daftar & History) lalu dibuatkan PKG BARU (kode baru, status
                // diproses). Unit tetap non-`tersedia` agar stage-nya jadi 'pengemasan'.
                if ($failed) {
                    InstrumentStock::transitionMany($failed, InstrumentStock::STATUS_STERILISASI, [
                        'context' => 'sterilization',
                        'reference' => $sterilization->code.' — gagal, dikembalikan ke packaging',
                    ]);
                    $this->returnFailedUnitsToPackaging($sterilization, $failed);
                }

                // Perbarui tahap unit setelah validasi (berhasil → tersedia/lanjut simpan;
                // gagal → kembali ke tahap pengemasan).
                InstrumentStock::syncStages(array_merge($passed, $failed));

                PipelineEvent::record(
                    PipelineEvent::STAGE_STERILIZATION,
                    $sterilization->code,
                    $anyPassed ? PipelineEvent::ACTION_SELESAI : PipelineEvent::ACTION_GAGAL,
                    ['note' => 'Validasi per unit: '.count($passed).' berhasil, '.count($failed).' gagal (dikembalikan ke packaging)'],
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

    /**
     * Kembalikan unit yang GAGAL steril ke tahap Packaging untuk diproses ulang.
     * Untuk tiap PKG yang memuat unit gagal:
     *  - item unit gagal di-void (`disabled`), jadi isi PKG lama menyusut tinggal
     *    unit yang lolos & tetap terlihat di History;
     *  - PKG lama sendiri hanya di-void bila SELURUH unitnya gagal;
     *  - dibuat record RPK baru (ronde berikutnya) berisi unit gagal saja, sehingga
     *    muncul lagi di tab Packaging.
     *
     * Unit yang SUDAH berhasil (rilis/`tersedia`) tidak akan ikut diproses ulang
     * karena pembuatan batch berikutnya hanya mengambil unit non-`tersedia`.
     *
     * @param  array<int>  $failedStockIds
     */
    private function returnFailedUnitsToPackaging(Sterilization $sterilization, array $failedStockIds): void
    {
        $failed = collect($failedStockIds)->map(fn ($v) => (int) $v)->unique();
        $sterilization->loadMissing('packagings.washing.production.items');

        foreach ($sterilization->packagings as $pkg) {
            if ($pkg->disabled) {
                continue;
            }

            // Isi ronde ini dibaca dari packaging_item (bukan seluruh unit produksi),
            // supaya PKG yang isinya sudah menyusut karena re-proses sebelumnya
            // tidak salah dinilai.
            $pkgStockIds = $pkg->items()->where('disabled', false)
                ->pluck('instrument_stock_id')->filter()->map(fn ($v) => (int) $v);

            // Lewati PKG yang tidak memuat unit gagal (semua unitnya berhasil).
            if ($pkgStockIds->intersect($failed)->isEmpty()) {
                continue;
            }

            // Item unit yang gagal di-void (pelacakan per unit) — isi PKG lama otomatis
            // menyusut tinggal unit yang lolos.
            $pkg->items()
                ->whereIn('instrument_stock_id', $failed->all())
                ->update(['disabled' => true, 'disabled_at' => now(), 'updated_by' => auth()->user()?->name]);

            // PKG lama hanya di-void bila SELURUH unitnya gagal. Pada kegagalan
            // sebagian, recordnya dibiarkan tampil di History berisi unit yang lolos
            // — kalau ikut di-void, jejak pengemasan unit yang berhasil hilang dari
            // tampilan petugas.
            if ($pkgStockIds->diff($failed)->isEmpty()) {
                $pkg->disabled = true;
                $pkg->disabled_at = now();
                $pkg->save();
            }

            // PKG BARU untuk pengemasan ulang — hanya berisi unit yang GAGAL. Diberi
            // prefix RPK (deret nomor sendiri) + `reprocess_of` yang menunjuk batch
            // asal, sehingga rantai re-prosesnya terlacak eksplisit.
            $newPkg = Packaging::create([
                'prefix' => Packaging::PREFIX_REPROCESS,
                'washing_code' => $pkg->washing_code,
                'reprocess_of' => $pkg->id,
                'round' => Packaging::nextRound($pkg->washing_code),
                'status' => Packaging::STATUS_DIPROSES,
            ]);
            $failedProdItems = ($pkg->washing?->production?->items ?? collect())
                ->whereIn('instrument_stock_id', $pkgStockIds->intersect($failed)->all());
            foreach ($failedProdItems as $pi) {
                $newPkg->items()->create([
                    'instrument_stock_id' => $pi->instrument_stock_id,
                    'source' => $pi->source,
                    'package_name' => $pi->package_name,
                    // Label baru: nomornya ikut kode RPK, bukan warisan PKG lama.
                    'barcode_no' => $newPkg->barcodeNoFor($pi->package_no),
                ]);
            }

            PipelineEvent::record(PipelineEvent::STAGE_PACKAGING, $newPkg->full_code, PipelineEvent::ACTION_DIBUAT, [
                'note' => 'Pengemasan ulang — unit gagal steril '.$sterilization->code.' dikembalikan (PKG lama '.$pkg->full_code.' di-void)',
            ]);
        }
    }

    /**
     * Item "siap-steril" satu PKG, dipecah per LABEL (packaging_item.barcode_no).
     * Satu label = satu kemasan fisik = satu baris yang bisa dicentang sendiri.
     * Label yang sudah masuk batch steril (`$batchedBarcodes`) dilewati.
     *
     * Nama baris diambil dari relasi production_item: nama PAKET untuk unit paket,
     * nama INSTRUMEN untuk unit satuan.
     */
    private function readyLabels(Packaging $packaging, $batchedBarcodes): Collection
    {
        $production = $packaging->washing?->production;
        $prodByStock = ($production?->items ?? collect())->keyBy('instrument_stock_id');

        return $packaging->items
            ->where('disabled', false)
            // Batch lama yang barcode_no-nya masih kosong dihitung ulang dari relasi.
            ->map(function ($item) use ($prodByStock, $packaging) {
                $item->barcode_no ??= $packaging->barcodeNoFor(
                    $prodByStock->get($item->instrument_stock_id)?->package_no
                );

                return $item;
            })
            ->reject(fn ($item) => $batchedBarcodes->has($item->barcode_no))
            ->groupBy('barcode_no')
            ->map(function ($items, $barcode) use ($packaging, $production, $prodByStock) {
                $first = $items->first();
                $prodFirst = $prodByStock->get($first->instrument_stock_id);
                $isPaket = ($prodFirst?->source ?? $first->source) === 'paket';

                return [
                    // Identitas baris = nomor label; dipakai FE saat mencentang.
                    'id' => $packaging->id,          // PKG induk (untuk warisan expiry)
                    'barcode_no' => $barcode,
                    'kind' => 'ready',
                    'reprocess' => false,
                    'stock_id' => null,
                    'code' => $packaging->full_code,
                    'code_transaction' => $production?->code,
                    'status' => 'selesai',
                    // Nama label: nama paket bila set, nama instrumen bila satuan.
                    'name' => $isPaket
                        ? ($prodFirst?->package_name ?? $first->package_name ?? 'Paket')
                        : ($prodFirst?->name ?? $first->instrumentStock?->instrument?->name ?? 'Instrumen'),
                    'borrowed_by' => $production?->displayName(),
                    'image_url' => $this->batchImage(
                        $items->map(fn ($i) => $prodByStock->get($i->instrument_stock_id))->filter()
                    ),
                    'processed_at' => $packaging->completed_at ?? $packaging->packaged_at,
                    'unit_count' => $items->count(),
                    'units' => $items->map(function ($item) use ($prodByStock, $barcode) {
                        $prod = $prodByStock->get($item->instrument_stock_id);

                        return [
                            ...$this->unitRow($prod ?? $item),
                            'id' => $item->id,
                            'barcode_no' => $barcode,
                        ];
                    })->values(),
                    'sterilization' => null,
                ];
            })
            ->values();
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

        // Nomor label (barcode_no) tiap unit, dibaca dari packaging_item PKG anggota
        // — dipakai frontend untuk mengelompokkan baris per label fisik.
        $barcodeByStock = $batch->packagings
            ->flatMap(fn (Packaging $p) => $p->items->where('disabled', false))
            ->filter(fn ($i) => $i->instrument_stock_id !== null)
            ->mapWithKeys(fn ($i) => [$i->instrument_stock_id => $i->barcode_no]);

        // Daftar unit = sterilization_items batch (sumber kebenaran: termasuk unit
        // re-proses lepas yang tidak berasal dari PKG). Item yang di-void (`disabled`,
        // yaitu unit gagal steril yang sudah dikembalikan ke tahap packaging) tidak
        // ikut ditampilkan — jejaknya ada di batch RPK penggantinya.
        $units = $batch->items->where('disabled', false)->values()
            ->map(function ($item) use ($prodByStock, $barcodeByStock) {
                $prod = $prodByStock->get($item->instrument_stock_id);

                return [
                    'id' => $item->id,
                    'instrument_stock_id' => $item->instrument_stock_id,
                    // Kode, nama & foto dari SNAPSHOT production_item ($prod) — bukan
                    // relasi live — agar riwayat batch tetap sama walau master berubah.
                    'code' => $prod?->kode_instrumen ?? $item->instrumentStock?->code,
                    'instrument' => $prod?->name ?? $item->instrumentStock?->instrument?->name,
                    'image_url' => $prod?->image_url ?? $item->instrumentStock?->instrument?->image_url,
                    'source' => $prod?->source ?? 'satuan',
                    'package_name' => $prod?->package_name,
                    'package_no' => $prod?->package_no,
                    'barcode_no' => $barcodeByStock[$item->instrument_stock_id] ?? null,
                    'result' => $item->result,
                ];
            })->values();

        // Nama gabungan (unik) dari UNIT yang benar-benar ada di batch ini (paket →
        // nama paket, satuan → nama instrumen). Sengaja TIDAK memakai displayName()
        // produksi anggota: itu menampilkan seluruh isi produksi asal — termasuk unit
        // yang tak ikut masuk batch ini — sehingga kartu jadi tak sinkron dengan
        // daftar "Hasil per Unit" (yang bersumber dari $units di bawah).
        $names = $units
            ->map(fn ($u) => ($u['source'] ?? 'satuan') === 'paket'
                ? ($u['package_name'] ?? 'Paket')
                : ($u['instrument'] ?? 'Instrumen'))
            ->filter()->unique()->values()->implode(', ');

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
            // Kode, nama & foto dari snapshot production_item; relasi live hanya cadangan
            // untuk batch lama yang dibuat sebelum kolom snapshot ada.
            'code' => $u->kode_instrumen ?? $u->instrumentStock?->code,
            'instrument' => $u->name ?? $u->instrumentStock?->instrument?->name,
            'image_url' => $u->image_url ?? $u->instrumentStock?->instrument?->image_url,
            'source' => $u->source,
            'package_name' => $u->package_name,
            // Nomor set (production_item) — dipakai frontend menghitung jumlah SET
            // paket, bukan jumlah instrumen di dalamnya.
            'package_no' => $u->package_no ?? null,
        ];
    }

    /**
     * Gambar utama batch: foto SET (baris paket) bila ada, jika tidak instrumen
     * pertama — keduanya dari SNAPSHOT production_item.image_url (paket menyimpan
     * foto katalog, satuan foto instrumen), bukan relasi/katalog live.
     */
    private function batchImage($units): ?string
    {
        return $units->firstWhere('source', 'paket')?->image_url
            ?? $units->first()?->image_url;
    }
}
