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

                // Tahap Produksi (PRD-NNN, code auto). Dianggap langsung selesai
                // karena batch dibuat & unit dikunci dalam satu aksi.
                $production = Production::create([
                    'source' => Production::SOURCE_INTERNAL,
                    'note' => $validated['note'] ?? null,
                    'status' => Production::STATUS_SELESAI,
                    'started_by' => $actor,
                    'started_at' => now(),
                    'completed_by' => $actor,
                    'completed_at' => now(),
                ]);

                // Potong stok: kunci unit terpilih ke batch sebagai production_item,
                // lalu ubah statusnya `tersedia` → `sterilisasi`.
                $pickedStockIds = $this->lockUnits($production, $requirements, $pools);

                InstrumentStock::transitionMany($pickedStockIds, InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'production',
                    'reference' => $production->code,
                    'note' => 'Stok dipotong untuk produksi CSSD',
                ]);

                // Buka tahap Cleaning (WSH-NNN) — dirangkai ke produksi via production_code.
                $washing = OrderWashing::create([
                    'production_code' => $production->code,
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                    'started_by' => $actor,
                    'started_at' => now(),
                ]);

                // Jejak pipeline: produksi selesai + masuk tahap cleaning.
                PipelineEvent::record(PipelineEvent::STAGE_PRODUCTION, $production->code, PipelineEvent::ACTION_SELESAI, [
                    'note' => 'Batch produksi CSSD dibuat ('.count($pickedStockIds).' unit dipotong dari stok)',
                ]);
                PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_DIBUAT, [
                    'note' => 'Masuk tahap Cleaning (dari produksi '.$production->code.')',
                ]);

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
     * @return array<int,array{source:string,package_name:?string,instrument_id:int,qty:int}>
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
        $add = function (string $source, ?string $packageName, ?int $instrumentId, int $qty) use (&$reqs) {
            if (! $instrumentId || $qty <= 0) {
                return;
            }
            $key = $source.'|'.$instrumentId.'|'.($packageName ?? '');
            $reqs[$key] ??= [
                'source' => $source,
                'package_name' => $packageName,
                'instrument_id' => $instrumentId,
                'qty' => 0,
            ];
            $reqs[$key]['qty'] += $qty;
        };

        foreach ($items as $item) {
            if ($item['type'] === 'paket') {
                $catalog = $catalogs->get($item['instrument_catalog_id'] ?? null);
                $packageName = $item['package_name'] ?? $catalog?->name ?? 'Paket';
                foreach (($catalog?->items ?? []) as $ci) {
                    $add('paket', $packageName, $ci->instrument_id, $item['quantity'] * $ci->quantity);
                }
            } else {
                $add('satuan', null, $item['instrument_id'] ?? null, $item['quantity']);
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

        return InstrumentStock::whereIn('instrument_id', $instrumentIds)
            ->where('status', InstrumentStock::STATUS_TERSEDIA)
            // Kecualikan unit yang fisiknya masih di gudang steril (tersimpan).
            ->whereNotIn('id', InstrumentStorage::query()
                ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
                ->select('instrument_stock_id'))
            ->orderBy('code')
            ->get()
            ->groupBy('instrument_id');
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
                    'source' => $req['source'],
                    'package_name' => $req['package_name'],
                    'condition_out_id' => $stock->condition_id,
                ]);
                $pickedStockIds[] = $stock->id;
            }

            $cursor[$instrumentId] = $start + $req['qty'];
        }

        return $pickedStockIds;
    }
}
