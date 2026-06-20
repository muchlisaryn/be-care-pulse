<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat ada order baru masuk (status diajukan), supaya halaman CSSD
 * memutar bunyi notifikasi & memperbarui badge secara real-time tanpa polling.
 *
 * Channel publik "orders" (tanpa data sensitif) — frontend cukup tahu ada order
 * baru lalu menarik ulang jumlahnya sendiri.
 */
class OrderSubmitted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    /**
     * Channel tempat event disiarkan.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('orders');
    }

    /**
     * Nama event yang didengarkan klien (Echo: `.order.submitted`).
     */
    public function broadcastAs(): string
    {
        return 'order.submitted';
    }

    /**
     * Payload ringkas yang dikirim ke klien.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'code' => $this->order->code,
            'room' => $this->order->room?->name,
            'borrowed_by' => $this->order->borrowed_by,
        ];
    }
}
