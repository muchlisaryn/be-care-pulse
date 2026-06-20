<?php

namespace App\Events;

use App\Models\OrderTransfer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat permintaan pinjam-alih disetujui (ACC) — instrumen berpindah ke
 * peminjam/ruangan baru. Halaman monitoring memakai sinyal ini untuk menyegarkan
 * distribusi ruangan & nama peminjam terbaru secara real-time.
 *
 * Channel publik "transfers", event `.transfer.responded`.
 */
class OrderTransferResponded implements ShouldBroadcastNow
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
        return 'transfer.responded';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->transfer->id,
            'status' => $this->transfer->status,
            'new_order_id' => $this->transfer->new_order_id,
        ];
    }
}
