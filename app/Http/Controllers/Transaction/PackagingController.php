<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\OrderWashing;
use App\Models\Packaging;
use App\Models\PackagingType;
use App\Models\PipelineEvent;
use App\Models\Sterilization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tahap Inspection & Packaging pada pipeline pemrosesan CSSD (record PKG+ymd+urutan harian).
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
     * Rincian unit per barcode_no beberapa batch packaging (lazy-load dari tombol
     * Detail di timeline) — by id packaging. Nama instrumen dari SNAPSHOT
     * production_item (via instrument_stock_id), relasi live hanya cadangan.
     */
    public function barcodeDetail(Request $request): JsonResponse
    {
        $ids = array_filter((array) $request->input('ids', []));
        if (empty($ids)) {
            return $this->success('Rincian packaging.', ['barcodes' => []]);
        }

        $packagings = Packaging::with(['items', 'washing.production.items.instrumentStock.instrument'])
            ->whereIn('id', $ids)->get();

        // Baris tabel Detail packaging: tanggal | code (barcode) | nama | nama petugas.
        $rows = collect();
        foreach ($packagings as $pkg) {
            $prodByStock = ($pkg->washing?->production?->items ?? collect())
                ->keyBy('instrument_stock_id');
            $at = $pkg->completed_at ?? $pkg->packaged_at ?? $pkg->started_at ?? $pkg->created_at;
            $petugas = $pkg->completed_by ?? $pkg->operator ?? $pkg->started_by;

            // Kelompokkan unit packaging ini per barcode_no (label fisik).
            $byBarcode = [];
            foreach ($pkg->items->where('disabled', false) as $it) {
                $bc = $it->barcode_no ?: '(tanpa barcode)';
                $prod = $prodByStock->get($it->instrument_stock_id);
                // Nama dari SNAPSHOT production_item (bukan master): paket → NAMA PAKET
                // langsung, satuan → nama instrumen. Relasi live hanya cadangan.
                $byBarcode[$bc][] = ($prod?->source === 'paket')
                    ? ($prod->package_name ?? 'Paket')
                    : ($prod?->name ?? $it->instrumentStock?->instrument?->name ?? 'Instrumen');
            }

            foreach ($byBarcode as $bc => $names) {
                $rows->push([
                    'tanggal' => $at,
                    'code' => $bc,
                    'name' => collect($names)->unique()->values()->implode(', '),
                    'petugas' => $petugas,
                ]);
            }
        }

        return $this->success('Rincian packaging.', ['rows' => $rows->values()]);
    }

    /**
     * Daftar batch pada tahap Packaging — dua sumber digabung:
     *  1. record `packaging` yang sudah dibuat (sedang diinspeksi / sudah dikemas);
     *  2. batch cleaning yang berstatus `selesai` tapi BELUM punya record packaging
     *     (antrean menunggu inspeksi) — recordnya baru dibuat lewat `start()`.
     *
     * Batch yang PKG-nya di-void (disabled) sengaja tidak dimunculkan lagi sebagai
     * antrean: `washing_code`-nya tetap terhitung sudah punya packaging.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->search;

        $packagings = Packaging::with(self::CHAIN)
            ->whereIn('status', [Packaging::STATUS_DIPROSES, Packaging::STATUS_SELESAI])
            // PKG yang di-void (unitnya gagal steril & diproses ulang) tidak ditampilkan.
            ->where('disabled', false)
            ->when(
                $search,
                // `code` hanya berisi angka, jadi pencarian dicocokkan ke kode utuh
                // (prefix + angka) supaya "PKG2605" tetap ketemu.
                fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('CONCAT(prefix, code) LIKE ?', ["%{$s}%"])
                    ->orWhere('washing_code', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%"))
            );

        // Tanggal acuan tahap Packaging: waktu dikemas, kalau belum pakai waktu
        // record mulai diproses / dibuat.
        $packagings = $this->applyDateRange(
            $packagings,
            $request,
            'COALESCE(packaged_at, started_at, created_at)'
        )->get();

        $pending = OrderWashing::with([
            'production.items.instrumentStock.instrument',
            'production.items.conditionOut',
        ])
            ->where('status', OrderWashing::STATUS_SELESAI)
            ->whereNotIn('code', Packaging::query()->whereNotNull('washing_code')->select('washing_code'))
            ->when(
                $search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('production_code', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%"))
            );

        // Batch antrean belum punya tanggal pengemasan — acuannya waktu cleaning
        // selesai, yaitu saat batch masuk antrean tahap ini.
        $pending = $this->applyDateRange(
            $pending,
            $request,
            'COALESCE(completed_at, created_at)'
        )->get();

        $rows = $packagings->map(fn (Packaging $p) => $this->transform($p))
            ->concat($pending->map(fn (OrderWashing $w) => $this->transformPending($w)))
            ->sortByDesc(fn (array $row) => $this->sortKey($row))
            ->values();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->success('Data tahap packaging berhasil diambil.', $paginator);
    }

    /**
     * "Selesai & Cetak Label" untuk batch ANTREAN (belum punya record packaging):
     * record `packaging` + `packaging_item` dibuat di sini, langsung berstatus
     * selesai. Sebelum ini batch hanya ada sebagai washing berstatus `selesai`,
     * jadi tidak ada baris packaging yang menumpuk bila petugas batal mengemas.
     */
    public function completeQueued(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'washing_code' => 'required|string',
            ...$this->completionRules(),
        ]);

        $washing = OrderWashing::where('code', $validated['washing_code'])->first();

        if (! $washing) {
            return $this->error('Batch cleaning tidak ditemukan.', 404);
        }

        if ($washing->status !== OrderWashing::STATUS_SELESAI) {
            return $this->error('Batch cleaning ini belum selesai dicuci.', 422);
        }

        if (Packaging::where('washing_code', $washing->code)->where('disabled', false)->exists()) {
            return $this->error('Batch ini sudah punya data pengemasan. Muat ulang daftarnya.', 422);
        }

        try {
            $packaging = DB::transaction(function () use ($validated, $washing) {
                $packaging = Packaging::create([
                    'prefix' => Packaging::PREFIX_NORMAL,
                    'washing_code' => $washing->code,
                    'round' => Packaging::nextRound($washing->code),
                    'status' => Packaging::STATUS_DIPROSES,
                ]);

                // Isi ronde ini = seluruh unit produksi (cermin production_item).
                foreach (($washing->production?->items()->get() ?? collect()) as $pi) {
                    $packaging->items()->create([
                        'instrument_stock_id' => $pi->instrument_stock_id,
                        'source' => $pi->source,
                        'package_name' => $pi->package_name,
                        // Nomor yang tercetak di label (tanpa spasi).
                        'barcode_no' => $packaging->barcodeNoFor($pi->package_no),
                    ]);
                }

                PipelineEvent::record(PipelineEvent::STAGE_PACKAGING, $packaging->full_code, PipelineEvent::ACTION_DIBUAT, [
                    'note' => 'Inspeksi & pengemasan (dari cleaning '.$washing->code.')',
                ]);

                $this->applyCompletion($packaging, $validated);

                return $packaging;
            });

            $packaging->refresh();

            return $this->success('Packaging selesai — batch siap masuk tahap sterilisasi.', [
                ...$this->transform($packaging),
                'label' => $this->labelPayload($packaging),
            ], 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Kunci pengurutan daftar batch (terbaru dulu), lintas dua sumber data. */
    private function sortKey(array $row): int
    {
        $at = $row['processed_at'] ?? null;

        return match (true) {
            $at instanceof \DateTimeInterface => $at->getTimestamp(),
            is_string($at) => (int) strtotime($at),
            default => 0,
        };
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

        $validated = $request->validate($this->completionRules());

        try {
            DB::transaction(fn () => $this->applyCompletion($packaging, $validated));

            $packaging->refresh();

            return $this->success('Packaging selesai — batch siap masuk tahap sterilisasi.', [
                ...$this->transform($packaging),
                'label' => $this->labelPayload($packaging),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Aturan validasi data pengemasan (dipakai dua jalur penyelesaian). */
    private function completionRules(): array
    {
        return [
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
        ];
    }

    /**
     * Tandai satu record packaging selesai + catat event & tahap unit. Dipanggil di
     * dalam transaksi oleh `complete()` (record sudah ada) maupun `completeQueued()`
     * (record baru saja dibuat).
     */
    private function applyCompletion(Packaging $packaging, array $validated): void
    {
        $actor = auth()->user()?->name;

        $packaging->status = Packaging::STATUS_SELESAI;
        $packaging->chemical_indicator = $validated['chemical_indicator'];
        $packaging->operator = $validated['operator'] ?? $packaging->operator ?? $actor;
        $packaging->packaged_at = $validated['packaged_at'] ?? now();
        $packaging->packaging_type_id = $validated['packaging_type_id'];
        // Snapshot tanggal: dihitung sekali di sini agar tidak ikut bergeser bila
        // masa simpan jenis kemasan diubah admin di kemudian hari.
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

        PipelineEvent::record(PipelineEvent::STAGE_PACKAGING, $packaging->full_code, PipelineEvent::ACTION_SELESAI, [
            'note' => 'Packaging selesai — indikator kimia '.$validated['chemical_indicator'].' — siap sterilisasi',
        ]);

        // Perbarui tahap unit (keluar dari pengemasan → siap sterilisasi).
        $stockIds = $packaging->items()->pluck('instrument_stock_id')->all();
        InstrumentStock::syncStages($stockIds);
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
        $packaging->loadMissing([...self::CHAIN, 'items.instrumentStock.instrument']);

        $production = $packaging->washing?->production;

        // Sumber label = detail per-unit tahap packaging (packaging_item), supaya
        // tiap label punya id sendiri (dipakai frontend: kode produksi + id item).
        // Unit yang di-void di tahap ini tidak ikut dicetak. Batch lama yang dibuat
        // sebelum packaging_item terisi jatuh balik ke unit produksi (id null).
        $packagingItems = $packaging->items->where('disabled', false)->values();
        $units = $packagingItems->isNotEmpty()
            ? $packagingItems
            : ($production ? $production->items : collect());

        // Nomor set (package_no) hanya ada di production_item — packaging_item tidak
        // menyimpannya. Dipetakan lewat instrument_stock_id agar label bisa memakai
        // nomor set pada kodenya.
        $packageNoByStock = ($production?->items ?? collect())
            ->filter(fn ($pi) => $pi->instrument_stock_id !== null)
            ->mapWithKeys(fn ($pi) => [$pi->instrument_stock_id => $pi->package_no]);

        // Nama instrumen juga dari SNAPSHOT production_item — packaging_item tidak
        // menyimpannya, jadi dipetakan lewat instrument_stock_id (relasi live cadangan).
        $prodByStock = ($production?->items ?? collect())
            ->filter(fn ($pi) => $pi->instrument_stock_id !== null)
            ->keyBy('instrument_stock_id');

        $packagedAt = $packaging->packaged_at ?? now();
        // Batch lama (dikemas sebelum kolom expiry_date ada) tetap pakai aturan default.
        $expiry = $packaging->expiry_date?->toDateString()
            ?? $packagedAt->copy()->addDays(Sterilization::STERILE_SHELF_LIFE_DAYS)->toDateString();

        return [
            'batch' => $production?->code ?? $packaging->full_code, // Nomor Batch (PRD / PKG)
            'packaging_code' => $packaging->full_code,
            // Prefix & angka dikirim TERPISAH — kode label disusun frontend sebagai
            // tiga segmen: prefix, nomor packaging, nomor set.
            'packaging_prefix' => $packaging->prefix,
            'packaging_number' => $packaging->code,
            'set_name' => $production?->displayName() ?? 'Produksi CSSD',
            'packer' => $packaging->operator,
            'packaging_type' => $packaging->packagingType?->name,
            'packaged_at' => $packagedAt->toIso8601String(),
            'expiry_date' => $expiry,
            'chemical_indicator' => $packaging->chemical_indicator,
            'items' => $units->map(fn ($u) => [
                // id packaging_item — null bila fallback ke unit produksi.
                'id' => $packagingItems->isNotEmpty() ? $u->id : null,
                'instrument_name' => $prodByStock->get($u->instrument_stock_id)?->name
                    ?? $u->instrumentStock?->instrument?->name ?? 'Instrumen',
                'unit_code' => $u->instrumentStock?->code,
                'source' => $u->source,
                'package_name' => $u->package_name,
                // Nomor set dalam batch produksi — dipakai frontend menyusun kode label.
                'package_no' => $packageNoByStock[$u->instrument_stock_id] ?? null,
                // Nomor barcode tersimpan (tanpa spasi) — untuk pencocokan hasil scan.
                // Batch lama yang belum punya kolom ini dihitung ulang dari relasi.
                'barcode_no' => $u->barcode_no
                    ?? $packaging->barcodeNoFor($packageNoByStock[$u->instrument_stock_id] ?? null),
            ])->values(),
        ];
    }

    /** Bentuk respons satu batch packaging agar cocok dengan tipe di frontend. */
    private function transform(Packaging $packaging): array
    {
        $packaging->loadMissing(self::CHAIN);

        return $this->batchPayload($packaging->washing, $packaging);
    }

    /**
     * Batch cleaning selesai yang BELUM punya record packaging — ditampilkan di tab
     * Packaging sebagai antrean menunggu inspeksi. `id`/`code` masih null sampai
     * `start()` dipanggil; frontend memakai `washing_code` sebagai identitasnya.
     */
    private function transformPending(OrderWashing $washing): array
    {
        return $this->batchPayload($washing, null);
    }

    /**
     * Payload satu batch tahap Packaging. `$packaging` null = batch masih antrean
     * (belum ada recordnya); field khusus packaging ikut null dan `started` false.
     */
    private function batchPayload(?OrderWashing $washing, ?Packaging $packaging): array
    {
        $production = $washing?->production;
        $productionItems = $production ? $production->items : collect();

        // Isi RONDE ini dibaca dari packaging_item — batch pengemasan ulang (RPK)
        // hanya memuat unit yang gagal steril, jadi tidak boleh diturunkan dari
        // seluruh unit produksi. Snapshot nama/kode/foto tetap diambil dari
        // production_item lewat pencocokan instrument_stock_id.
        // Batch antrean (belum ada record) otomatis memakai seluruh unit produksi.
        $units = $productionItems;

        if ($packaging) {
            $roundStockIds = $packaging->items()
                ->where('disabled', false)
                ->pluck('instrument_stock_id')
                ->filter();

            if ($roundStockIds->isNotEmpty()) {
                $units = $productionItems->whereIn('instrument_stock_id', $roundStockIds->all())->values();
            }
        }

        // Ringkasan chip kartu: unit paket dikelompokkan per paket; satuan per instrumen.
        $items = $units
            ->groupBy(fn ($u) => $u->source === 'paket'
                ? 'paket|'.($u->package_name ?? 'Paket')
                // Nama instrumen dari snapshot production_item.name (relasi live cadangan).
                : 'satuan|'.($u->name ?? $u->instrumentStock?->instrument?->name ?? 'Instrumen'))
            ->map(function ($group) {
                $first = $group->first();
                $isPaket = $first->source === 'paket';

                return [
                    'type' => $isPaket ? 'paket' : 'satuan',
                    'name' => $isPaket
                        ? ($first->package_name ?? 'Paket')
                        : ($first->name ?? $first->instrumentStock?->instrument?->name ?? 'Instrumen'),
                    // Paket dihitung per SET (package_no), bukan per instrumen di
                    // dalamnya — 2 set partus berisi 6 instrumen = 2, bukan 12. Batch
                    // lama tanpa package_no (null) melebur jadi satu set.
                    'quantity' => $isPaket
                        ? $group->pluck('package_no')->unique()->count()
                        : $group->count(),
                ];
            })
            ->values();

        return [
            'id' => $packaging?->id,
            'code' => $packaging?->full_code,                 // prefix + angka, mis. PKG26050201
            'code_transaction' => $production?->code,         // PRD+ymd+urutan harian (di kartu)
            'washing_code' => $washing?->code,                // WSH+ymd+urutan harian
            // Penanda record packaging sudah ada. false = masih antrean (record baru
            // dibuat saat "Selesai & Cetak Label" lewat `completeQueued()`).
            'started' => $packaging !== null,
            // Ronde pengemasan untuk batch cleaning yang sama: 1 = pengemasan
            // pertama, 2+ = pengemasan ulang (RPK) setelah gagal steril.
            'round' => $packaging?->round ?? 1,
            'status' => 'pengemasan',
            'stage_status' => $packaging?->status ?? Packaging::STATUS_DIPROSES,
            'borrowed_by' => $production?->displayName(),
            'processed_at' => $production?->created_at ?? $packaging?->started_at ?? $washing?->completed_at,
            'processed_by' => $packaging?->started_by ?? $washing?->completed_by,
            // Petugas yang menyelesaikan pengemasan + waktunya (untuk riwayat).
            'completed_by' => $packaging?->completed_by,
            'completed_at' => $packaging?->completed_at,
            'operator' => $packaging?->operator,
            'chemical_indicator' => $packaging?->chemical_indicator, // = No. Lot indikator kimia
            'packaging_type_id' => $packaging?->packaging_type_id,
            'packaging_type_label' => $packaging?->packagingType?->name,
            'packaged_at' => $packaging?->packaged_at,
            'expiry_date' => $packaging?->expiry_date?->toDateString(),
            'units_count' => $units->count(),
            'items' => $items,
            'units' => $units->map(fn ($u) => [
                'id' => $u->id,
                'source' => $u->source,
                'package_name' => $u->package_name,
                // Set ke-berapa dalam batch — dipakai menghitung jumlah set paket.
                'package_no' => $u->package_no,
                'instrument_stock_id' => $u->instrument_stock_id,
                'code' => $u->instrumentStock?->code,
                // Nama instrumen dari snapshot production_item — dipakai sebagai label
                // checklist inspeksi; relasi ke master hanya cadangan batch lama.
                'name' => $u->name ?? $u->instrumentStock?->instrument?->name,
                // Foto paket (unit paket) / foto instrumen (unit satuan) dari snapshot
                // production_item; relasi ke master hanya cadangan untuk batch lama.
                'image_url' => $u->image_url ?? $u->instrumentStock?->instrument?->image_url,
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
