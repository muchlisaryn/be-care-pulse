<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\InstrumentCatalog;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderWashing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Produksi CSSD — awal lifecycle pemrosesan. CSSD memproses stok alat miliknya
 * sendiri (tanpa order peminjam) langsung masuk antrean Cleaning. Membuat order
 * INTERNAL (room_id null, borrowed_by = "Produksi CSSD") berstatus `pencucian`,
 * sehingga mengalir ke pipeline yang ada: Cleaning → Packaging → Sterilization →
 * Storage.
 *
 * Saat "Mulai Produksi", stok langsung DIPOTONG: sejumlah unit `tersedia` per
 * instrumen dipilih, dikunci ke batch sebagai order_item, lalu statusnya diubah
 * `tersedia` → `sterilisasi`. Unit yang sama mengalir lewat pipeline (Packaging
 * tidak meng-generate ulang) dan kembali `tersedia` saat sterilisasi selesai.
 */
class ProductionController extends Controller
{
    /** Label peminjam untuk batch produksi internal (pembeda di daftar Cleaning). */
    public const PRODUCER_LABEL = 'Produksi CSSD';

    /**
     * Mulai produksi: buat batch internal berisi baris permintaan (jenis + jumlah)
     * lalu langsung tempatkan di tahap Cleaning.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string',
            // Baris produksi: hanya jenis & jumlah. Unit fisik di-generate saat Packaging.
            'items' => 'required|array|min:1',
            'items.*.type' => ['required', Rule::in(['satuan', 'paket'])],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.instrument_id' => 'required_if:items.*.type,satuan|nullable|integer|exists:instruments,id',
            'items.*.instrument_catalog_id' => 'required_if:items.*.type,paket|nullable|integer|exists:instrument_catalogs,id',
            'items.*.package_name' => 'nullable|string|max:255',
        ]);

        try {
            $order = DB::transaction(function () use ($validated) {
                // Jabarkan baris produksi menjadi kebutuhan unit per (asal, paket,
                // instrumen). Paket diuraikan ke isi katalog × jumlah set.
                $requirements = $this->buildRequirements($validated['items']);

                // Ambil pool unit `tersedia` per instrumen (sekali query) lalu
                // pastikan stok cukup SEBELUM membuat batch. Bila kurang → batal.
                $pools = $this->availablePools($requirements);
                $this->assertStockSufficient($requirements, $pools);

                $order = Order::create([
                    // Kode produksi terpisah (PRD-NNN) agar deret ORD-NNN khusus
                    // order peminjaman & tidak "terserap" batch produksi.
                    'code' => $this->generateProductionCode(),
                    // Internal CSSD — tanpa ruangan peminjam.
                    'room_id' => null,
                    'user_id' => auth()->id(),
                    'borrowed_by' => self::PRODUCER_LABEL,
                    'order_date' => now()->toDateString(),
                    'note' => $validated['note'] ?? null,
                    // Langsung masuk tahap Cleaning (sudah "diproses" oleh CSSD).
                    'status' => Order::STATUS_PENCUCIAN,
                    'processed_at' => now(),
                    'processed_by' => auth()->user()?->name,
                ]);

                foreach ($validated['items'] as $item) {
                    $isPaket = $item['type'] === 'paket';
                    $order->requestItems()->create([
                        'type' => $item['type'],
                        'instrument_id' => $isPaket ? null : ($item['instrument_id'] ?? null),
                        'instrument_catalog_id' => $isPaket ? ($item['instrument_catalog_id'] ?? null) : null,
                        'package_name' => $isPaket ? ($item['package_name'] ?? null) : null,
                        'quantity' => $item['quantity'],
                    ]);
                }

                // Potong stok: kunci unit terpilih ke batch sebagai order_item, lalu
                // ubah statusnya `tersedia` → `sterilisasi`. Karena unit sudah ada,
                // tahap Packaging tidak akan meng-generate ulang.
                $pickedStockIds = $this->lockUnits($order, $requirements, $pools);

                InstrumentStock::transitionMany($pickedStockIds, InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'production',
                    'reference' => $order->code,
                    'note' => 'Stok dipotong untuk produksi CSSD',
                ]);

                // Catatan pencucian kosong (diisi operator di menu Cleaning).
                $order->washing()->firstOrCreate([], [
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                ]);

                // Timeline: dibuat + langsung diproses ke Cleaning.
                OrderEvent::record(OrderEvent::TYPE_DIBUAT, $order, [
                    'note' => 'Batch produksi CSSD dibuat',
                ]);
                OrderEvent::record(OrderEvent::TYPE_DIPROSES, $order, [
                    'note' => 'Produksi masuk tahap Cleaning ('.count($pickedStockIds).' unit dipotong dari stok)',
                ]);

                return $order;
            });

            $order->load(['requestItems.instrument', 'requestItems.catalog', 'washing', 'items.instrumentStock']);

            return $this->success('Batch produksi berhasil dibuat & masuk tahap Cleaning.', $order, 201);
        } catch (\RuntimeException $e) {
            // Stok tidak cukup — tolak dengan 422 (validasi bisnis, bukan error server).
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Jabarkan baris produksi (satuan/paket) menjadi daftar kebutuhan unit per
     * (asal, nama paket, instrumen) — selaras dengan buildRequirements milik
     * OrderController agar order_item yang dikunci di sini dianggap "sudah
     * di-generate" oleh tahap Packaging (tidak digenerate ulang).
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
     * @return Collection<int,Collection<int,InstrumentStock>>
     */
    private function availablePools(array $requirements)
    {
        $instrumentIds = collect($requirements)->pluck('instrument_id')->unique()->values()->all();

        return InstrumentStock::whereIn('instrument_id', $instrumentIds)
            ->where('status', InstrumentStock::STATUS_TERSEDIA)
            // Kecualikan unit yang fisiknya masih di gudang steril (tersimpan).
            // Statusnya tetap `tersedia` agar bisa masuk batch sterilisasi, tapi
            // tidak boleh ditarik ke produksi baru → mencegah baris gudang ganda.
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
     * Kunci unit terpilih ke order sebagai order_item (per kebutuhan), tanpa
     * tumpang tindih antar kebutuhan yang berbagi instrumen yang sama.
     *
     * @return array<int,int> daftar instrument_stock_id yang dipotong
     */
    private function lockUnits(Order $order, array $requirements, $pools): array
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
                $order->items()->create([
                    'instrument_stock_id' => $stock->id,
                    'source' => $req['source'],
                    'package_name' => $req['package_name'],
                    'condition_out_id' => $stock->condition_id,
                    'is_returned' => false,
                ]);
                $pickedStockIds[] = $stock->id;
            }

            $cursor[$instrumentId] = $start + $req['qty'];
        }

        return $pickedStockIds;
    }

    /** Kode batch produksi berikutnya: PRD-NNN (deret terpisah dari ORD peminjaman). */
    private function generateProductionCode(): string
    {
        $maxCode = Order::withoutGlobalScopes()
            ->where('code', 'like', 'PRD-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'PRD-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
