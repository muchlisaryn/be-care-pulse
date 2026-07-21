<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\OrderItem;
use App\Models\OrderWashing;
use App\Models\Packaging;
use App\Models\ProductionItem;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class InstrumentStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = InstrumentStock::with(['instrument', 'condition'])
            ->when($request->instrument_id, fn ($q, $id) => $q->where('instrument_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('instrument', fn ($q) => $q->where('name', 'like', "%{$s}%"))
            )
            ->paginate(20);

        // Lampirkan tahap pipeline aktual tiap unit (pencucian/pengemasan/sterilisasi/
        // disimpan/dipinjam). Kolom `stage` dipersist & di-maintain saat transisi;
        // di sini dihitung ulang agar tampilan dijamin mutakhir.
        $stages = InstrumentStock::computeStages($data->getCollection()->pluck('id'));
        $data->getCollection()->each(function ($s) use ($stages) {
            $s->stage = $stages[$s->id]['stage'] ?? null;
            $s->stage_label = $stages[$s->id]['label'] ?? null;
        });

        return $this->success('Data stok instrumen berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instrument_id' => 'required|integer|exists:instruments,id',
            'condition_id' => 'nullable|integer|exists:conditions,id',
            'status' => ['nullable', Rule::in(InstrumentStock::STATUSES)],
        ]);

        try {
            $stock = InstrumentStock::create($validated);
            $stock->load(['instrument', 'condition']);

            return $this->success('Stok instrumen berhasil ditambahkan.', $stock, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(InstrumentStock $instrumentStock): JsonResponse
    {
        $instrumentStock->load(['instrument', 'condition']);

        return $this->success('Detail stok instrumen berhasil diambil.', $instrumentStock);
    }

    public function update(Request $request, InstrumentStock $instrumentStock): JsonResponse
    {
        $validated = $request->validate([
            'instrument_id' => 'required|integer|exists:instruments,id',
            'condition_id' => 'nullable|integer|exists:conditions,id',
            'status' => ['nullable', Rule::in(InstrumentStock::STATUSES)],
        ]);

        try {
            $instrumentStock->update($validated);
            $instrumentStock->load(['instrument', 'condition']);

            return $this->success('Stok instrumen berhasil diperbarui.', $instrumentStock);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(InstrumentStock $instrumentStock): JsonResponse
    {
        try {
            $instrumentStock->delete();

            return $this->success('Stok instrumen berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Hasilkan QR Code (SVG) dari kode unit instrumen, untuk dicetak jadi label.
     * Dikembalikan sebagai data URI base64 agar mudah ditampilkan/dicetak di frontend.
     */
    public function qr(InstrumentStock $instrumentStock): JsonResponse
    {
        try {
            $svg = QrCode::format('svg')->size(300)->margin(1)->generate($instrumentStock->code);

            return $this->success('QR Code instrumen berhasil dibuat.', [
                'code' => $instrumentStock->code,
                'qr_svg' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Cari unit instrumen berdasarkan kode hasil scan QR Code.
     * Dipakai saat serah-terima (peminjaman/pengembalian) agar tidak perlu ketik manual.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $stock = InstrumentStock::with(['instrument', 'condition'])
            ->where('code', $validated['code'])
            ->first();

        if (! $stock) {
            return $this->error('Instrumen dengan kode tersebut tidak ditemukan.', 404);
        }

        return $this->success('Instrumen ditemukan.', $stock);
    }

    /**
     * Riwayat pergerakan/perubahan status sebuah unit instrumen (terbaru dulu).
     */
    public function logs(InstrumentStock $instrumentStock): JsonResponse
    {
        $logs = $instrumentStock->logs()->latest('id')->paginate(20);

        return $this->success('Riwayat pergerakan unit berhasil diambil.', $logs);
    }

    /**
     * Lacak posisi unit di pipeline CSSD: tahap saat ini + kode produksinya.
     * Dipakai di katalog/master instrumen — saat status unit bukan `tersedia`,
     * tampilkan sedang ada di tahap apa (Produksi → Cleaning → Packaging →
     * Sterilisasi → Penyimpanan → Dipinjam) beserta kode batch tiap tahap.
     */
    public function tracking(InstrumentStock $instrumentStock): JsonResponse
    {
        $instrumentStock->load(['instrument', 'condition']);

        // Rekonstruksi rantai pipeline dari unit. Titik masuk = batch produksi
        // (production_item); tahap berikutnya dirangkai lewat code antar-tahap.
        $productionItem = ProductionItem::with('production')
            ->where('instrument_stock_id', $instrumentStock->id)
            ->latest('id')
            ->first();
        $production = $productionItem?->production;

        $washing = $production
            ? OrderWashing::where('production_code', $production->code)->latest('id')->first()
            : null;

        $packaging = $washing
            ? Packaging::where('washing_code', $washing->code)->latest('id')->first()
            : null;

        // Sterilisasi: utamakan lewat sterilization_items (per unit); fallback via packaging.
        $sterilization = SterilizationItem::with('sterilization')
            ->where('instrument_stock_id', $instrumentStock->id)
            ->latest('id')
            ->first()?->sterilization
            ?? $packaging?->sterilization;

        $storage = InstrumentStorage::where('instrument_stock_id', $instrumentStock->id)
            ->latest('id')
            ->first();

        // Peminjaman aktif (belum dikembalikan).
        $orderItem = OrderItem::with('order.room')
            ->where('instrument_stock_id', $instrumentStock->id)
            ->where('is_returned', false)
            ->latest('id')
            ->first();
        $order = $orderItem?->order;

        // PERJALANAN LENGKAP siklus produksi aktif: setiap tahap yang sudah/ sedang
        // dilalui unit ditampilkan berurutan — Produksi → Pencucian → Pengemasan →
        // Sterilisasi → Disimpan di Rak → Dipinjam. Tahap saat ini ditandai terpisah.
        $stages = [];
        if ($production) {
            // Produksi tak punya kolom status — begitu batch ada, tahapnya selesai.
            $stages[] = $this->stage('produksi', 'Produksi', $production->code, 'selesai', $production->created_at);
        }
        if ($washing) {
            $stages[] = $this->stage('pencucian', 'Pencucian & Disinfeksi', $washing->code, $washing->status, $washing->completed_at ?? $washing->started_at ?? $washing->created_at);
        }
        if ($packaging) {
            $stages[] = $this->stage('pengemasan', 'Pengemasan (Packing)', $packaging->full_code, $packaging->status, $packaging->packaged_at ?? $packaging->created_at);
        }
        if ($sterilization) {
            $stages[] = $this->stage('sterilisasi', 'Sterilisasi', $sterilization->code, $sterilization->status, $sterilization->completed_at ?? $sterilization->sterilized_at ?? $sterilization->created_at);
        }
        if ($storage) {
            $stages[] = $this->stage('disimpan', 'Disimpan di Rak', $storage->rack_code, $storage->status, $storage->stored_at ?? $storage->created_at);
        }
        // Peminjaman: tampil bila unit sedang/pernah dipinjam pada siklus ini.
        if ($order) {
            $stages[] = $this->stage('dipinjam', 'Dipinjam', $order->code, $order->status, $order->order_date ?? $order->created_at);
        }

        // Tahap saat ini dari perhitungan tahap aktual (akurat), lalu dicocokkan ke
        // entri perjalanan. Fallback ke status unit bila unit tidak di pipeline.
        $currentKey = InstrumentStock::computeStages([$instrumentStock->id])[$instrumentStock->id]['stage'] ?? null;
        $current = null;
        if ($currentKey) {
            foreach ($stages as $st) {
                if ($st['key'] === $currentKey) {
                    $current = $st;
                    break;
                }
            }
            $current ??= $this->stage(
                $currentKey,
                InstrumentStock::STAGE_LABELS[$currentKey] ?? $this->unitStatusLabel($instrumentStock->status),
                null,
                $currentKey,
                null,
            );
        } elseif ($instrumentStock->status !== InstrumentStock::STATUS_TERSEDIA) {
            $current = $this->stage($instrumentStock->status, $this->unitStatusLabel($instrumentStock->status), null, $instrumentStock->status, null);
        }

        return $this->success('Tracking unit instrumen berhasil diambil.', [
            'unit' => [
                'id' => $instrumentStock->id,
                'code' => $instrumentStock->code,
                'status' => $instrumentStock->status,
                'status_label' => $this->unitStatusLabel($instrumentStock->status),
                'instrument' => $instrumentStock->instrument
                    ? ['code' => $instrumentStock->instrument->code, 'name' => $instrumentStock->instrument->name]
                    : null,
                'condition' => $instrumentStock->condition?->name,
            ],
            // Kode batch produksi asal unit ini (PRD-...), null bila belum pernah diproduksi.
            'production_code' => $production?->code,
            'current_stage' => $current,
            'stages' => $stages,
            'order' => $order ? [
                'code' => $order->code,
                'code_transaction' => $order->code_transaction,
                'status' => $order->status,
                'borrowed_by' => $order->borrowed_by,
                'room' => $order->room?->name,
            ] : null,
            // Riwayat perubahan status (terbaru dulu) — konteks + kode referensi.
            'history' => $instrumentStock->logs()->latest('id')->limit(30)->get()->map(fn ($log) => [
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'context' => $log->context,
                'reference_code' => $log->reference_code,
                'note' => $log->note,
                'by' => $log->created_by,
                'at' => $log->created_at,
            ]),
        ]);
    }

    /** Bentuk satu entri tahap pipeline. */
    private function stage(string $key, string $label, ?string $code, ?string $status, $at): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'code' => $code,
            'status' => $status,
            'at' => $at,
        ];
    }

    private function unitStatusLabel(string $status): string
    {
        return [
            InstrumentStock::STATUS_TERSEDIA => 'Tersedia',
            InstrumentStock::STATUS_DIPINJAM => 'Dipinjam',
            InstrumentStock::STATUS_STERILISASI => 'Dalam Proses CSSD',
            InstrumentStock::STATUS_DIKEMBALIKAN => 'Dikembalikan',
        ][$status] ?? ucfirst($status);
    }
}
