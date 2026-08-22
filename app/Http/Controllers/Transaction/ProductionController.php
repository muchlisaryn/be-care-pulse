<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\OrderItem;
use App\Models\OrderWashing;
use App\Models\PackagingItem;
use App\Models\PipelineEvent;
use App\Models\Production;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Produksi CSSD — tahap awal pipeline (produksi → cleaning → packaging → steril).
 * CSSD memproses stok alat miliknya sendiri: membuat batch `production` (PRD-NNN)
 * berisi unit yang dikunci (production_item), lalu langsung membuka tahap Cleaning
 * (record `washing`) yang dirangkai lewat production_code.
 *
 * Saat "Mulai Produksi", stok langsung DIPOTONG: sejumlah unit `tersedia` per
 * instrumen dipilih, dikunci ke batch sebagai production_item, lalu statusnya
 * diubah `tersedia` → `sterilisasi`.
 */
class ProductionController extends Controller
{
    /**
     * Rincian instrumen beberapa batch produksi (lazy-load dari tombol Detail di
     * timeline). Dikembalikan sebagai baris tabel: tanggal | nomor label | nomor
     * produksi | nama | jumlah. Nama & pengelompokan dari SNAPSHOT production_item.
     *
     * `?order_id=` (opsional) menyaring ke unit yang dipinjam order tsb saja —
     * dipakai modal Pengembalian Instrumen supaya isi batch milik order lain tidak
     * ikut tampil. Lihat OrderItem::stockIdsOfOrder().
     */
    public function detail(Request $request): JsonResponse
    {
        $codes = array_filter((array) $request->input('codes', []));
        if (empty($codes)) {
            return $this->success('Rincian produksi.', ['items' => []]);
        }

        $onlyStockIds = OrderItem::stockIdsOfOrder($request->input('order_id'));
        // Nomor label kemasan tiap unit pada siklus ini (label lahir di tahap
        // packaging; di sini dipakai agar tiap tahap bisa dicocokkan per label).
        $barcodeByStock = PackagingItem::barcodeMapByProductionCodes($codes);

        $items = Production::with('items.instrumentStock.instrument')
            ->whereIn('code', $codes)
            ->get()
            ->sortBy(fn ($p) => optional($p->created_at)->timestamp ?? 0)
            ->flatMap(fn ($p) => $p->items
                ->when(
                    $onlyStockIds !== null,
                    fn ($items) => $items->whereIn('instrument_stock_id', $onlyStockIds)
                )
                // Dipecah per NOMOR LABEL juga: satu baris = satu bungkus, supaya
                // barisnya bisa ditelusuri balik ke label fisiknya.
                ->groupBy(fn ($pi) => ($pi->source === 'paket'
                    ? 'paket|'.($pi->package_name ?? 'Paket')
                    : 'satuan|'.($pi->name ?? 'Instrumen'))
                    .'|'.($barcodeByStock[$pi->instrument_stock_id] ?? ''))
                ->map(function ($g) use ($p, $barcodeByStock) {
                    $first = $g->first();
                    $isPaket = $first->source === 'paket';

                    return [
                        'tanggal' => $p->created_at,
                        'code' => $p->code,
                        'barcode_no' => $barcodeByStock[$first->instrument_stock_id] ?? null,
                        'name' => $isPaket ? ($first->package_name ?? 'Paket') : ($first->name ?? 'Instrumen'),
                        'type' => $isPaket ? 'paket' : 'satuan',
                        'qty' => $isPaket ? $g->pluck('package_no')->unique()->count() : $g->count(),
                        // Petugas = yang membuat/mulai batch produksi.
                        'petugas' => $p->created_by,
                    ];
                })->values())
            ->values();

        return $this->success('Rincian produksi.', ['items' => $items]);
    }

    /**
     * Mulai produksi: buat batch produksi berisi unit terpilih lalu langsung
     * buka tahap Cleaning (washing). Jejak tiap tahap dicatat di pipeline_events.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string',
            // Baris produksi: hanya jenis & jumlah. Unit fisik dikunci di sini.
            'items' => 'required|array|min:1',
            'items.*.type' => ['required', Rule::in(['satuan', 'paket'])],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.instrument_id' => 'required_if:items.*.type,satuan|nullable|integer|exists:instruments,id',
            'items.*.instrument_catalog_id' => 'required_if:items.*.type,paket|nullable|integer|exists:instrument_catalogs,id',
            'items.*.package_name' => 'nullable|string|max:255',
        ]);

        try {
            $production = DB::transaction(function () use ($validated) {
                // Jabarkan baris produksi menjadi kebutuhan unit per (asal, paket,
                // instrumen). Paket diuraikan ke isi katalog × jumlah set.
                $requirements = $this->buildRequirements($validated['items']);

                // Ambil pool unit `tersedia` per instrumen (sekali query) lalu
                // pastikan stok cukup SEBELUM membuat batch. Bila kurang → batal.
                $pools = $this->availablePools($requirements);
                $this->assertStockSufficient($requirements, $pools);

                $actor = auth()->user()?->name;

                // Tahap Produksi (PRD-NNN, code auto). Tanpa status & tanpa jejak
                // mulai/selesai: batch dibuat & unit dikunci dalam satu aksi, jadi
                // `created_at`/`created_by` (diisi trait audit) sudah mewakili
                // waktu batch dibuat berikut pelakunya.
                $production = Production::create([
                    'note' => $validated['note'] ?? null,
                ]);

                // Potong stok: kunci unit terpilih ke batch sebagai production_item,
                // lalu ubah statusnya `tersedia` → `sterilisasi`.
                $pickedStockIds = $this->lockUnits($production, $requirements, $pools);

                InstrumentStock::transitionMany($pickedStockIds, InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'production',
                    'reference' => $production->code,
                    'note' => 'Stok dipotong untuk produksi CSSD',
                ]);

                // Unit yang ditarik dari gudang steril untuk diproduksi ulang: tutup
                // baris gudangnya (tersimpan → keluar) supaya tidak dihitung dua kali
                // sebagai stok steril & tidak menyisakan baris ganda saat disimpan lagi.
                $this->closeStorageForReprocessed($pickedStockIds);

                // Buka tahap Cleaning (WSH+ymd+urutan harian) — dirangkai ke produksi via production_code.
                $washing = OrderWashing::create([
                    'production_code' => $production->code,
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                    'started_by' => $actor,
                    'started_at' => now(),
                ]);

                // Detail per-unit tahap Cleaning (washing_item) — cermin production_item.
                // Berada dalam transaksi yang sama: gagal simpan detail = seluruh batch
                // produksi ikut rollback.
                foreach ($production->items()->get() as $pi) {
                    $washing->items()->create([
                        'instrument_stock_id' => $pi->instrument_stock_id,
                        'source' => $pi->source,
                        'package_name' => $pi->package_name,
                    ]);
                }

                // Jejak pipeline: produksi selesai + masuk tahap cleaning.
                PipelineEvent::record(PipelineEvent::STAGE_PRODUCTION, $production->code, PipelineEvent::ACTION_SELESAI, [
                    'note' => 'Batch produksi CSSD dibuat ('.count($pickedStockIds).' unit dipotong dari stok)',
                ]);
                PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_DIBUAT, [
                    'note' => 'Masuk tahap Cleaning (dari produksi '.$production->code.')',
                ]);

                // Perbarui tahap unit (→ pencucian).
                InstrumentStock::syncStages($pickedStockIds);

                return $production;
            });

            $production->load('items.instrumentStock', 'washings');

            return $this->success('Batch produksi berhasil dibuat & masuk tahap Cleaning.', $production, 201);
        } catch (\RuntimeException $e) {
            // Stok tidak cukup — tolak dengan 422 (validasi bisnis, bukan error server).
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Jabarkan baris produksi (satuan/paket) menjadi daftar kebutuhan unit per
     * (asal, nama paket, instrumen). Paket diuraikan ke isi katalog × jumlah set.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array{source:string,package_name:?string,package_no:?int,package_image:?string,instrument_id:int,qty:int}>
     */
    private function buildRequirements(array $items): array
    {
        // Muat sekaligus semua katalog paket yang dipakai (beserta isinya).
        $catalogIds = collect($items)
            ->where('type', 'paket')
            ->pluck('instrument_catalog_id')
            ->filter()
            ->unique()
            ->all();
        $catalogs = InstrumentCatalog::with('items')->whereIn('id', $catalogIds)->get()->keyBy('id');

        $reqs = [];
        // `$packageNo` = set ke-berapa dalam batch (null utk satuan); ikut jadi bagian
        // key agar dua set bernama sama TIDAK melebur jadi satu kebutuhan.
        // `$packageImage` = path foto katalog paket, ikut di-snapshot ke production_item.
        $add = function (string $source, ?string $packageName, ?int $packageNo, ?string $packageImage, ?int $instrumentId, int $qty) use (&$reqs) {
            if (! $instrumentId || $qty <= 0) {
                return;
            }
            $key = $source.'|'.$instrumentId.'|'.($packageName ?? '').'|'.($packageNo ?? '');
            $reqs[$key] ??= [
                'source' => $source,
                'package_name' => $packageName,
                'package_no' => $packageNo,
                'package_image' => $packageImage,
                'instrument_id' => $instrumentId,
                'qty' => 0,
            ];
            $reqs[$key]['qty'] += $qty;
        };

        // Nomor satuan pesanan, berurut per batch & lintas jenis baris: TIAP QTY dapat
        // satu nomor. "gunting 3 + set partus 3" → nomor 1..6 (1-3 gunting satuan,
        // 4-6 set partus). Unit dalam satu set berbagi satu nomor.
        $packageNo = 0;

        foreach ($items as $item) {
            $qty = (int) $item['quantity'];

            if ($item['type'] === 'paket') {
                $catalog = $catalogs->get($item['instrument_catalog_id'] ?? null);
                // Nama katalog SELALU menang atas `package_name` kiriman klien: nama
                // inilah yang jadi kunci pencocokan stok steril paket di gudang
                // (production_item.package_name, dicocokkan persis). Teks bebas dari
                // klien hanya dipakai bila katalognya tidak ketemu — kalau tidak, satu
                // typo saja sudah cukup membuat stok masuk ember yang tak dikenali
                // katalog manapun sehingga paketnya tak pernah bisa didistribusikan.
                $packageName = $catalog?->name ?? $item['package_name'] ?? 'Paket';

                // Tiap set dijabarkan SENDIRI-SENDIRI (bukan qty × isi katalog) supaya
                // set ke-1 dan ke-2 jadi kelompok terpisah dengan unit fisik berbeda.
                for ($n = 0; $n < $qty; $n++) {
                    $packageNo++;
                    foreach (($catalog?->items ?? []) as $ci) {
                        $add('paket', $packageName, $packageNo, $catalog?->image, $ci->instrument_id, $ci->quantity);
                    }
                }
            } else {
                // Satuan pun dipecah per unit agar tiap qty punya nomornya sendiri.
                for ($n = 0; $n < $qty; $n++) {
                    $packageNo++;
                    $add('satuan', null, $packageNo, null, $item['instrument_id'] ?? null, 1);
                }
            }
        }

        return array_values($reqs);
    }

    /**
     * Pool unit `tersedia` per instrumen (urut kode) untuk seluruh kebutuhan.
     *
     * @param  array<int,array{instrument_id:int}>  $requirements
     */
    private function availablePools(array $requirements)
    {
        $instrumentIds = collect($requirements)->pluck('instrument_id')->unique()->values()->all();

        // Unit yang sudah jadi stok steril siap pakai di rak DIKELUARKAN dari
        // kandidat produksi. Sebelumnya ikut terambil — dan karena petugas hanya
        // memilih jenis + jumlah (bukan unit fisiknya), baris gudangnya ditutup
        // diam-diam oleh closeStorageForReprocessed dan stoknya lenyap dari
        // Gudang Steril tanpa pernah dipinjam.
        //
        // Yang dikecualikan hanya yang MASIH berlaku; unit kedaluwarsa tetap
        // boleh diproduksi ulang — lihat InstrumentStorage::readyStockIds().
        //
        // `instrument` ikut dimuat: namanya di-snapshot ke production_item.
        return InstrumentStock::with('instrument')
            ->whereIn('instrument_id', $instrumentIds)
            ->where('status', InstrumentStock::STATUS_TERSEDIA)
            ->whereNotIn('id', InstrumentStorage::readyStockIds())
            ->orderBy('code')
            ->get()
            ->groupBy('instrument_id');
    }

    /**
     * Berapa unit per instrumen yang tidak bisa dipakai karena sedang jadi stok
     * steril siap pakai di rak. Hanya untuk memperjelas pesan kekurangan stok.
     *
     * @param  array<int,int>  $instrumentIds
     * @return array<int,int> instrument_id => jumlah
     */
    private function heldInStorage(array $instrumentIds): array
    {
        $ids = InstrumentStorage::readyStockIds();

        if ($ids === []) {
            return [];
        }

        return InstrumentStock::whereIn('id', $ids)
            ->whereIn('instrument_id', $instrumentIds)
            ->selectRaw('instrument_id, COUNT(*) as jumlah')
            ->groupBy('instrument_id')
            ->pluck('jumlah', 'instrument_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Tutup baris gudang steril (status `tersimpan` → `keluar`) untuk unit yang
     * ditarik kembali ke produksi. Mencegah unit terhitung ganda sebagai stok steril
     * dan mencegah baris gudang ganda saat unit disimpan lagi di akhir siklus baru.
     *
     * Tetap diperlukan meski stok steril yang masih berlaku sudah dikeluarkan dari
     * kandidat produksi: unit KEDALUWARSA masih boleh diproduksi ulang, dan baris
     * raknya wajib ditutup saat itu terjadi.
     * Bila unit itu bagian sebuah paket, paket tsb otomatis jadi tak lengkap
     * (available_sterile_sets berkurang) — konsekuensi wajar menarik komponennya.
     *
     * @param  array<int,int>  $stockIds
     */
    private function closeStorageForReprocessed(array $stockIds): void
    {
        if (empty($stockIds)) {
            return;
        }

        InstrumentStorage::where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->whereIn('instrument_stock_id', $stockIds)
            ->update([
                'status' => InstrumentStorage::STATUS_KELUAR,
                // WAJIB ikut diisi — inilah jejak "baris ini sudah tidak di rak" yang
                // dipakai perhitungan sisa stok. Tanpa ini barisnya cuma ditandai lewat
                // `status`, dan unit yang ditarik ke produksi tetap terhitung ada di rak
                // (jalur order di OrderController sudah mengisinya sejak awal).
                'removed_at' => now(),
                'updated_by' => auth()->user()?->name,
            ]);
    }

    /**
     * Pastikan jumlah unit `tersedia` cukup untuk total kebutuhan tiap instrumen.
     * Bila kurang, lempar RuntimeException (ditangkap → 422) tanpa membuat batch.
     */
    private function assertStockSufficient(array $requirements, $pools): void
    {
        $neededByInstrument = [];
        foreach ($requirements as $req) {
            $neededByInstrument[$req['instrument_id']] = ($neededByInstrument[$req['instrument_id']] ?? 0) + $req['qty'];
        }

        $names = Instrument::whereIn('id', array_keys($neededByInstrument))->pluck('name', 'id');
        // Tanpa keterangan ini, petugas melihat "tersedia 2" padahal Master
        // menampilkan 5 unit bertanda Tersedia — selisihnya ada di rak.
        $diRak = $this->heldInStorage(array_keys($neededByInstrument));

        foreach ($neededByInstrument as $instrumentId => $needed) {
            $available = $pools->get($instrumentId)?->count() ?? 0;
            if ($available < $needed) {
                $name = $names[$instrumentId] ?? "#$instrumentId";
                $tertahan = $diRak[$instrumentId] ?? 0;
                $keterangan = $tertahan > 0
                    ? " {$tertahan} unit lain sudah jadi stok steril di gudang dan tidak bisa diproduksi ulang selama masih berlaku."
                    : '';

                throw new \RuntimeException(
                    "Stok \"{$name}\" tidak cukup: butuh {$needed}, tersedia {$available}.".$keterangan
                );
            }
        }
    }

    /**
     * Kunci unit terpilih ke batch produksi sebagai production_item (per
     * kebutuhan), tanpa tumpang tindih antar kebutuhan yang berbagi instrumen sama.
     *
     * @return array<int,int> daftar instrument_stock_id yang dipotong
     */
    private function lockUnits(Production $production, array $requirements, $pools): array
    {
        $cursor = []; // instrument_id => offset unit berikutnya di pool
        $pickedStockIds = [];

        foreach ($requirements as $req) {
            $instrumentId = $req['instrument_id'];
            $pool = $pools->get($instrumentId) ?? collect();
            $start = $cursor[$instrumentId] ?? 0;

            for ($n = 0; $n < $req['qty']; $n++) {
                $stock = $pool[$start + $n] ?? null;
                if (! $stock) {
                    // Tidak seharusnya terjadi (sudah divalidasi), jaga-jaga saja.
                    throw new \RuntimeException('Stok berubah saat proses produksi. Coba lagi.');
                }
                $production->items()->create([
                    'instrument_stock_id' => $stock->id,
                    // Snapshot: kode unit, nama & foto dibekukan di sini agar riwayat
                    // batch tidak ikut berubah bila master diubah nanti. `image` =
                    // path relatif (bukan URL) supaya tak basi bila host berubah, dan
                    // diisi foto KATALOG untuk baris paket / foto INSTRUMEN untuk
                    // baris satuan (paket tanpa foto katalog jatuh ke foto instrumen).
                    'kode_instrumen' => $stock->code,
                    'name' => $stock->instrument?->name,
                    'image' => $req['source'] === 'paket'
                        ? ($req['package_image'] ?? $stock->instrument?->image)
                        : $stock->instrument?->image,
                    'source' => $req['source'],
                    'package_name' => $req['package_name'],
                    'package_no' => $req['package_no'],
                    'condition_out_id' => $stock->condition_id,
                ]);
                $pickedStockIds[] = $stock->id;
            }

            $cursor[$instrumentId] = $start + $req['qty'];
        }

        return $pickedStockIds;
    }
}
