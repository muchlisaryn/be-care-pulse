<?php

namespace App\Events;

use App\Models\OrderTransfer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat ada permintaan pinjam-alih (handover) baru, supaya pemegang
 * instrumen saat ini langsung mendapat notifikasi & badge "Permintaan Pinjam"
 * secara real-time tanpa polling.
 *
 * Channel publik "transfers" (tanpa data sensitif) — frontend cukup tahu ada
 * permintaan baru lalu menarik ulang jumlah inbox-nya sendiri (difilter per
 * pemegang di sisi server).
 */
class OrderTransferRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OrderTransfer $transfer)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('transfers');
    }

    public function broadcastAs(): string
    {
        return 'transfer.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->transfer->id,
            'holder_user_id' => $this->transfer->holder_user_id,
            'from_order_code' => $this->transfer->fromOrder?->code,
            'to_room' => $this->transfer->toRoom?->name,
        ];
    }
}
