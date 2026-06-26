<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderWashing;
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
            'washing',
            'requestItems.instrument',
            'requestItems.catalog',
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
     * Simpan / perbarui catatan pencucian sebuah order. Bila `complete = true`,
     * tandai "Selesai Cuci" → order lanjut ke tahap pengemasan.
     */
    public function updateWashing(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, [Order::STATUS_PENCUCIAN, Order::STATUS_PENGEMASAN], true)) {
            return $this->error('Order ini tidak sedang dalam tahap cleaning.', 422);
        }

        $validated = $request->validate([
            'machine_no' => 'nullable|string|max:255',
            'operator' => 'nullable|string|max:255',
            'temperature' => 'nullable|string|max:50',
            'washed_at' => 'nullable|date',
            'detergent_type' => 'nullable|string|max:255',
            'complete' => 'sometimes|boolean',
            'completed_at' => 'nullable|date',
        ]);

        try {
            DB::transaction(function () use ($validated, $order) {
                $washing = $order->washing()->firstOrCreate([], [
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                ]);

                $washing->fill(array_intersect_key(
                    $validated,
                    array_flip(['machine_no', 'operator', 'temperature', 'washed_at', 'detergent_type'])
                ));

                // Tandai Selesai Cuci → catatan selesai & order lanjut ke pengemasan.
                // Waktu selesai memakai input operator bila ada, jika tidak now().
                if (! empty($validated['complete'])) {
                    $completedAt = $validated['completed_at'] ?? now();
                    $washing->status = OrderWashing::STATUS_SELESAI;
                    $washing->completed_at = $completedAt;
                    $washing->washed_at ??= $completedAt;
                }

                $washing->save();

                if (! empty($validated['complete']) && $order->status === Order::STATUS_PENCUCIAN) {
                    $order->status = Order::STATUS_PENGEMASAN;
                    $order->save();
                    OrderEvent::record(OrderEvent::TYPE_SELESAI_CUCI, $order, [
                        'note' => 'Pencucian selesai, order lanjut ke pengemasan',
                    ]);
                }
            });

            $order->load(['room', 'washing']);

            return $this->success('Catatan pencucian berhasil disimpan.', $this->transform($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Bentuk respons order tahap cleaning untuk frontend. */
    private function transform(Order $order): array
    {
        $order->loadMissing(['room', 'user', 'washing', 'requestItems.instrument', 'requestItems.catalog']);
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
            'washing' => $w ? [
                'id' => $w->id,
                'machine_no' => $w->machine_no,
                'operator' => $w->operator,
                'temperature' => $w->temperature,
                'washed_at' => $w->washed_at,
                'detergent_type' => $w->detergent_type,
                'status' => $w->status,
                'completed_at' => $w->completed_at,
            ] : null,
        ];
    }
}
