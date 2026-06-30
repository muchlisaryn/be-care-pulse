<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderWashing;
use App\Models\WasherMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tahap Cleaning & Pengemasan pada pipeline pemrosesan CSSD.
 *
 * Alur: order masuk (diajukan) → Proses → pencucian (Cleaning) → pengemasan.
 * "Proses" hanya mencatat waktu & memindahkan stage; catatan pencucian
 * (nomor mesin, operator, suhu, waktu, deterjen) diisi di menu Cleaning.
 */
class CleaningController extends Controller
{
    /**
     * Proses order masuk: pindahkan dari "diajukan" ke tahap pencucian (Cleaning).
     * Hanya mencatat kapan & oleh siapa diproses + buat catatan pencucian kosong;
     * tidak ada alokasi unit fisik.
     */
    public function process(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_DIAJUKAN) {
            return $this->error('Order ini sudah diproses dan tidak bisa diproses lagi.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $order->status = Order::STATUS_PENCUCIAN;
                $order->processed_at = now();
                $order->processed_by = auth()->user()?->name;
                $order->save();

                // Catatan pencucian dibuat kosong (Dalam Proses Pencucian), diisi
                // operator kemudian di menu Cleaning.
                $order->washing()->firstOrCreate([], [
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                ]);

                OrderEvent::record(OrderEvent::TYPE_DIPROSES, $order, [
                    'note' => 'Order diproses & masuk tahap Cleaning',
                ]);
            });

            $order->load(['room', 'washing']);

            return $this->success('Order berhasil diproses & masuk tahap Cleaning.', $this->transform($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Daftar order pada tahap Cleaning & Pengemasan (status pencucian/pengemasan)
     * beserta catatan pencucian & ringkasan permintaan.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'washing.washerMachine',
            'requestItems.instrument',
            'requestItems.catalog',
            'items.instrumentStock.instrument',
            'items.conditionOut',
        ])
            ->whereIn('status', [Order::STATUS_PENCUCIAN, Order::STATUS_PENGEMASAN])
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->orderByDesc('processed_at')
            ->latest()
            ->paginate(20);

        $orders->getCollection()->transform(fn (Order $order) => $this->transform($order));

        return $this->success('Data order tahap cleaning berhasil diambil.', $orders);
    }

    /**
     * Daftar notifikasi kegagalan pencucian: order yang catatan pencuciannya
     * memiliki alert parameter (suhu/durasi di luar ambang mesin washer).
     */
    public function alerts(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'washing.washerMachine',
            'requestItems.instrument',
            'requestItems.catalog',
            'items.instrumentStock.instrument',
            'items.conditionOut',
        ])
            ->whereIn('status', [Order::STATUS_PENCUCIAN, Order::STATUS_PENGEMASAN])
            ->whereHas('washing', fn ($q) => $q->where('alert', true))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest()
            ->paginate(20);

        $orders->getCollection()->transform(fn (Order $order) => $this->transform($order));

        return $this->success('Daftar notifikasi kegagalan pencucian berhasil diambil.', $orders);
    }

    /**
     * Simpan / perbarui catatan pencucian sebuah order.
     *
     * - `washer_machine_id` mencatat mesin washer yang dipindai; suhu & durasi
     *   dievaluasi terhadap ambang mesin → bila di luar rentang, sistem menandai
     *   `alert` (notifikasi kegagalan suhu/waktu).
     * - `complete = true` menandai "Selesai Cuci" → order lanjut ke pengemasan.
     *   Tidak diizinkan selama masih ada alert parameter.
     * - `fail = true` menandai pencucian "Gagal" (wajib diulang); order tetap di
     *   tahap pencucian.
     */
    public function updateWashing(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, [Order::STATUS_PENCUCIAN, Order::STATUS_PENGEMASAN], true)) {
            return $this->error('Order ini tidak sedang dalam tahap cleaning.', 422);
        }

        $validated = $request->validate([
            'washer_machine_id' => 'nullable|exists:washer_machines,id',
            'machine_no' => 'nullable|string|max:255',
            'operator' => 'nullable|string|max:255',
            'temperature' => 'nullable|string|max:50',
            'washed_at' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:0',
            'detergent_type' => 'nullable|string|max:255',
            'complete' => 'sometimes|boolean',
            'completed_at' => 'nullable|date',
            'fail' => 'sometimes|boolean',
            'failure_reason' => 'nullable|string|max:255',
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $order) {
                $washing = $order->washing()->firstOrCreate([], [
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                ]);

                $washing->fill(array_intersect_key(
                    $validated,
                    array_flip(['washer_machine_id', 'machine_no', 'operator', 'temperature', 'washed_at', 'duration_minutes', 'detergent_type'])
                ));

                // Evaluasi parameter terhadap ambang mesin washer yang dipindai.
                $alerts = [];
                if ($washing->washer_machine_id && ($machine = WasherMachine::find($washing->washer_machine_id))) {
                    $temp = is_numeric($washing->temperature) ? (float) $washing->temperature : null;
                    $alerts = $machine->evaluate($temp, $washing->duration_minutes);
                    // Lengkapi nomor mesin dari master bila belum diisi manual.
                    $washing->machine_no ??= $machine->code;
                }
                $washing->alert = ! empty($alerts);
                $washing->alert_message = $alerts ? implode(' ', $alerts) : null;

                // Tandai Gagal → wajib diulang; order tetap di tahap pencucian.
                if (! empty($validated['fail'])) {
                    $washing->status = OrderWashing::STATUS_GAGAL;
                    $washing->failure_reason = $validated['failure_reason']
                        ?? $washing->alert_message
                        ?? 'Pencucian gagal.';
                    $washing->save();

                    if ($order->status === Order::STATUS_PENGEMASAN) {
                        $order->status = Order::STATUS_PENCUCIAN;
                        $order->save();
                    }

                    OrderEvent::record(OrderEvent::TYPE_GAGAL_CUCI, $order, [
                        'note' => 'Pencucian gagal: '.$washing->failure_reason,
                    ]);

                    return ['blocked' => false, 'failed' => true];
                }

                $completing = ! empty($validated['complete']);

                // Selesai Cuci diblokir selama parameter masih di luar ambang mesin.
                if ($completing && $washing->alert) {
                    $washing->save();

                    return ['blocked' => true, 'failed' => false];
                }

                if ($completing) {
                    $completedAt = $validated['completed_at'] ?? now();
                    $washing->status = OrderWashing::STATUS_SELESAI;
                    $washing->completed_at = $completedAt;
                    $washing->washed_at ??= $completedAt;
                    $washing->failure_reason = null;
                } elseif ($washing->status === OrderWashing::STATUS_GAGAL) {
                    // Disimpan ulang setelah perbaikan parameter → kembali Dalam Proses.
                    $washing->status = OrderWashing::STATUS_DALAM_PROSES;
                    $washing->failure_reason = null;
                }

                $washing->save();

                if ($completing && $order->status === Order::STATUS_PENCUCIAN) {
                    $order->status = Order::STATUS_PENGEMASAN;
                    $order->save();
                    OrderEvent::record(OrderEvent::TYPE_SELESAI_CUCI, $order, [
                        'note' => 'Pencucian selesai, order lanjut ke pengemasan',
                    ]);
                }

                return ['blocked' => false, 'failed' => false];
            });

            $order->load(['room', 'washing.washerMachine']);

            if ($result['blocked']) {
                return $this->error(
                    'Parameter pencucian di luar ambang mesin: '.$order->washing->alert_message.' Periksa ulang atau tandai gagal.',
                    422
                );
            }

            $message = $result['failed']
                ? 'Pencucian ditandai gagal dan harus diulang.'
                : 'Catatan pencucian berhasil disimpan.';

            return $this->success($message, $this->transform($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Bentuk respons order tahap cleaning untuk frontend. */
    private function transform(Order $order): array
    {
        $order->loadMissing([
            'room', 'user', 'washing.washerMachine',
            'requestItems.instrument', 'requestItems.catalog',
            'items.instrumentStock.instrument', 'items.conditionOut',
        ]);
        $w = $order->washing;

        return [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            'order_date' => $order->order_date,
            'processed_at' => $order->processed_at,
            'processed_by' => $order->processed_by,
            'requested_qty' => (int) $order->requestItems->sum('quantity'),
            'request_lines' => $order->requestItems->count(),
            'items' => $order->requestItems->map(fn ($it) => [
                'type' => $it->type,
                'name' => $it->type === 'paket'
                    ? ($it->package_name ?? $it->catalog?->name ?? 'Paket')
                    : ($it->instrument?->name ?? "Instrumen #{$it->instrument_id}"),
                'quantity' => (int) $it->quantity,
            ])->values(),
            // Unit fisik yang dikunci ke batch (terisi untuk batch Produksi CSSD;
            // kosong untuk order peminjaman yang unitnya baru di-generate saat Packaging).
            'units_count' => $order->items->count(),
            'units' => $order->items->map(fn (OrderItem $it) => [
                'id' => $it->id,
                'source' => $it->source,
                'package_name' => $it->package_name,
                'instrument_stock_id' => $it->instrument_stock_id,
                'code' => $it->instrumentStock?->code,
                'instrument' => $it->instrumentStock?->instrument
                    ? ['id' => $it->instrumentStock->instrument->id, 'name' => $it->instrumentStock->instrument->name]
                    : null,
                'status' => $it->instrumentStock?->status,
                'condition_out' => $it->conditionOut
                    ? ['id' => $it->conditionOut->id, 'name' => $it->conditionOut->name]
                    : null,
            ])->values(),
            'washing' => $w ? [
                'id' => $w->id,
                'washer_machine_id' => $w->washer_machine_id,
                'washer_machine' => $w->washerMachine ? [
                    'id' => $w->washerMachine->id,
                    'code' => $w->washerMachine->code,
                    'name' => $w->washerMachine->name,
                ] : null,
                'machine_no' => $w->machine_no,
                'operator' => $w->operator,
                'temperature' => $w->temperature,
                'washed_at' => $w->washed_at,
                'duration_minutes' => $w->duration_minutes,
                'detergent_type' => $w->detergent_type,
                'status' => $w->status,
                'alert' => $w->alert,
                'alert_message' => $w->alert_message,
                'failure_reason' => $w->failure_reason,
                'completed_at' => $w->completed_at,
            ] : null,
        ];
    }
}
