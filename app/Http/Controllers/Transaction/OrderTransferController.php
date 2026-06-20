<?php

namespace App\Http\Controllers\Transaction;

use App\Events\OrderTransferRequested;
use App\Events\OrderTransferResponded;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderTransferController extends Controller
{
    /** Relasi yang dimuat saat menampilkan permintaan transfer. */
    private const RELATIONS = [
        'fromOrder.room',
        'toRoom',
        'requestedBy',
        'holder',
        'newOrder',
        'items.instrumentStock.instrument',
    ];

    /**
     * Daftar permintaan pinjam-alih.
     * - box=incoming (default): permintaan masuk untuk user (sebagai pemegang yang meng-ACC).
     * - box=outgoing: permintaan yang diajukan user (peminjam baru) — untuk pantau status.
     */
    public function index(Request $request): JsonResponse
    {
        $box = $request->query('box', 'incoming');
        $column = $box === 'outgoing' ? 'requested_by_user_id' : 'holder_user_id';

        $data = OrderTransfer::with(self::RELATIONS)
            ->where($column, auth()->id())
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('fromOrder', fn ($o) => $o->where('code', 'like', "%{$s}%")
                        ->orWhere('code_transaction', 'like', "%{$s}%"))
                    ->orWhereHas('toRoom', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest()
            ->paginate(20);

        return $this->success('Daftar permintaan pinjam berhasil diambil.', $data);
    }

    /** Jumlah permintaan masuk yang masih pending (untuk badge notifikasi). */
    public function incomingCount(): JsonResponse
    {
        $count = OrderTransfer::where('holder_user_id', auth()->id())
            ->where('status', OrderTransfer::STATUS_PENDING)
            ->count();

        return $this->success('Jumlah permintaan pinjam masuk berhasil diambil.', ['count' => $count]);
    }

    /**
     * Buat permintaan pinjam-alih: peminjam baru meminta sebagian unit dari order
     * yang sedang dipinjam pihak lain. Request dikirim ke pemegang saat ini untuk di-ACC.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_order_id' => 'required|integer|exists:order,id',
            'to_room_id' => 'required|integer|exists:rooms,id',
            'borrowed_by' => 'nullable|string|max:255',
            'note' => 'nullable|string',
            'instrument_stock_ids' => 'required|array|min:1',
            'instrument_stock_ids.*' => 'integer',
        ]);

        try {
            $transfer = DB::transaction(function () use ($validated) {
                $fromOrder = Order::findOrFail($validated['from_order_id']);

                if ($fromOrder->status !== Order::STATUS_DIPINJAM) {
                    throw new \RuntimeException('Order sumber sedang tidak dipinjam, tidak bisa diminta.');
                }

                // Peminjam dibedakan per RUANGAN. Pindah ke ruangan yang sama tidak ada gunanya.
                if ((int) $fromOrder->room_id === (int) $validated['to_room_id']) {
                    throw new \RuntimeException('Instrumen sudah berada di ruangan tujuan tersebut.');
                }

                // Cegah request dobel: unit yang masih punya permintaan pinjam pending
                // tidak boleh diminta lagi.
                $duplicate = OrderTransfer::where('from_order_id', $fromOrder->id)
                    ->where('status', OrderTransfer::STATUS_PENDING)
                    ->whereHas('items', fn ($q) => $q->whereIn('instrument_stock_id', $validated['instrument_stock_ids']))
                    ->exists();
                if ($duplicate) {
                    throw new \RuntimeException('Sebagian unit sudah memiliki permintaan pinjam yang menunggu persetujuan.');
                }

                // Hanya unit milik order sumber yang belum dikembalikan yang boleh diminta.
                $items = OrderItem::where('order_id', $fromOrder->id)
                    ->where('is_returned', false)
                    ->whereIn('instrument_stock_id', $validated['instrument_stock_ids'])
                    ->get();

                if ($items->count() !== count(array_unique($validated['instrument_stock_ids']))) {
                    throw new \RuntimeException('Sebagian unit tidak valid / sudah dikembalikan / bukan milik order ini.');
                }

                $transfer = OrderTransfer::create([
                    'from_order_id' => $fromOrder->id,
                    'holder_user_id' => $fromOrder->user_id,
                    'requested_by_user_id' => auth()->id(),
                    'to_room_id' => $validated['to_room_id'],
                    'borrowed_by' => $validated['borrowed_by'] ?? null,
                    'note' => $validated['note'] ?? null,
                    'status' => OrderTransfer::STATUS_PENDING,
                ]);

                foreach ($items as $item) {
                    $transfer->items()->create([
                        'instrument_stock_id' => $item->instrument_stock_id,
                        'source' => $item->source,
                        'package_name' => $item->package_name,
                    ]);
                }

                return $transfer;
            });

            $transfer->load(self::RELATIONS);

            broadcast(new OrderTransferRequested($transfer));

            return $this->success('Permintaan pinjam berhasil dikirim ke peminjam saat ini.', $transfer, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * ACC permintaan oleh pemegang saat ini: pindahkan unit terpilih ke order baru
     * milik peminjam baru (berbagi code_transaction yang sama, tanpa order ulang ke
     * CSSD). Unit tetap berstatus `dipinjam`; hanya berpindah pemegang/ruangan.
     */
    public function accept(OrderTransfer $orderTransfer): JsonResponse
    {
        if ($orderTransfer->holder_user_id !== auth()->id()) {
            return $this->error('Hanya pemegang instrumen saat ini yang dapat menyetujui.', 403);
        }

        if ($orderTransfer->status !== OrderTransfer::STATUS_PENDING) {
            return $this->error('Permintaan ini sudah diproses.', 422);
        }

        try {
            DB::transaction(function () use ($orderTransfer) {
                $orderTransfer->load(['items', 'fromOrder.room', 'toRoom']);
                $fromOrder = $orderTransfer->fromOrder;

                $stockIds = $orderTransfer->items->pluck('instrument_stock_id')->all();

                // Unit yang diminta harus masih dipegang order sumber & belum dikembalikan.
                $orderItems = OrderItem::where('order_id', $fromOrder->id)
                    ->where('is_returned', false)
                    ->whereIn('instrument_stock_id', $stockIds)
                    ->get();

                if ($orderItems->count() !== count($stockIds)) {
                    throw new \RuntimeException('Sebagian unit sudah tidak tersedia pada order sumber.');
                }

                // Order baru untuk peminjam baru — berbagi code_transaction (invoice) sama.
                $newOrder = Order::create([
                    'room_id' => $orderTransfer->to_room_id,
                    'user_id' => $orderTransfer->requested_by_user_id,
                    'code_transaction' => $fromOrder->code_transaction,
                    'borrowed_by' => $orderTransfer->borrowed_by,
                    'order_date' => now()->toDateString(),
                    'return_plan_date' => $fromOrder->return_plan_date,
                    'status' => Order::STATUS_DIPINJAM,
                    'note' => 'Pinjam-alih dari order '.$fromOrder->code,
                ]);

                // Pindahkan unit ke order baru (status stok tetap dipinjam).
                OrderItem::whereIn('id', $orderItems->pluck('id'))->update(['order_id' => $newOrder->id]);

                // Timeline: catat perpindahan pada code_transaction yang sama.
                $fromRoom = $fromOrder->room?->name ?? '—';
                $toRoom = $orderTransfer->toRoom?->name ?? '—';
                OrderEvent::record(OrderEvent::TYPE_DIPINDAH, $newOrder, [
                    'note' => "Dipinjam dari ruangan {$fromRoom} ke {$toRoom}"
                        .($orderTransfer->borrowed_by ? " oleh {$orderTransfer->borrowed_by}" : ''),
                ]);

                $orderTransfer->update([
                    'status' => OrderTransfer::STATUS_ACCEPTED,
                    'responded_at' => now(),
                    'new_order_id' => $newOrder->id,
                ]);
            });

            $orderTransfer->load(self::RELATIONS);

            // Siarkan agar monitoring (distribusi ruangan & nama peminjam terbaru)
            // tersegarkan real-time di klien lain yang sedang membuka halaman itu.
            broadcast(new OrderTransferResponded($orderTransfer));

            return $this->success('Permintaan disetujui. Instrumen telah berpindah ke peminjam baru.', $orderTransfer);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Batalkan permintaan oleh pengaju selama belum di-ACC (masih pending). */
    public function cancel(OrderTransfer $orderTransfer): JsonResponse
    {
        if ($orderTransfer->requested_by_user_id !== auth()->id()) {
            return $this->error('Hanya pengaju permintaan yang dapat membatalkan.', 403);
        }

        if ($orderTransfer->status !== OrderTransfer::STATUS_PENDING) {
            return $this->error('Permintaan ini sudah diproses dan tidak bisa dibatalkan.', 422);
        }

        try {
            $orderTransfer->update([
                'status' => OrderTransfer::STATUS_CANCELED,
                'responded_at' => now(),
            ]);

            $orderTransfer->load(self::RELATIONS);

            return $this->success('Permintaan pinjam dibatalkan.', $orderTransfer);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Tolak permintaan oleh pemegang saat ini. */
    public function reject(OrderTransfer $orderTransfer): JsonResponse
    {
        if ($orderTransfer->holder_user_id !== auth()->id()) {
            return $this->error('Hanya pemegang instrumen saat ini yang dapat menolak.', 403);
        }

        if ($orderTransfer->status !== OrderTransfer::STATUS_PENDING) {
            return $this->error('Permintaan ini sudah diproses.', 422);
        }

        try {
            $orderTransfer->update([
                'status' => OrderTransfer::STATUS_REJECTED,
                'responded_at' => now(),
            ]);

            $orderTransfer->load(self::RELATIONS);

            return $this->success('Permintaan pinjam ditolak.', $orderTransfer);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
