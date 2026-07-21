<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\OrderWashing;
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
                $packageName = $item['package_name'] ?? $catalog?->name ?? 'Paket';

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

        // Ketersediaan produksi MENGIKUTI stok `tersedia` di Master (unit ber-badge
        // "Tersedia"), termasuk unit yang masih tersimpan di gudang steril — bila unit
        // gudang ikut dipilih, baris gudangnya ditutup saat batch dibuat (lihat
        // closeStorageForReprocessed) agar tidak jadi stok steril ganda.
        // `instrument` ikut dimuat: namanya di-snapshot ke production_item.
        return InstrumentStock::with('instrument')
            ->whereIn('instrument_id', $instrumentIds)
            ->where('status', InstrumentStock::STATUS_TERSEDIA)
            ->orderBy('code')
            ->get()
            ->groupBy('instrument_id');
    }

    /**
     * Tutup baris gudang steril (status `tersimpan` → `keluar`) untuk unit yang
     * ditarik kembali ke produksi. Mencegah unit terhitung ganda sebagai stok steril
     * dan mencegah baris gudang ganda saat unit disimpan lagi di akhir siklus baru.
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

        foreach ($neededByInstrument as $instrumentId => $needed) {
            $available = $pools->get($instrumentId)?->count() ?? 0;
            if ($available < $needed) {
                $name = $names[$instrumentId] ?? "#$instrumentId";
                throw new \RuntimeException("Stok \"{$name}\" tidak cukup: butuh {$needed}, tersedia {$available}.");
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
