<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;

/**
 * Aktivitas TERAKHIR sebuah order — bagian "Tracking" pada modal Pengembalian
 * Instrumen & Riwayat Pengembalian.
 *
 * SENGAJA berdiri sendiri, tidak digabung dengan endpoint lain: modal itu hanya
 * memajang satu baris (posisi order saat ini), jadi tidak boleh ikut menanggung
 * biaya perakitan timeline penuh. Riwayat lengkapnya baru diambil saat tombol
 * "Tampilkan semua tracking" ditekan, lewat endpoint tersendiri
 * `GET /api/master/orders/{order}/timeline`.
 *
 * Bedanya jauh: timeline penuh menelusuri seluruh pipeline CSSD tiap unit
 * (produksi → cleaning → sterilisasi → gudang, lihat OrderController::pipelineTimeline),
 * sedangkan di sini cukup SATU baris teratas dari tabel `order_events`.
 */
class OrderTrackingController extends Controller
{
    /**
     * GET /api/master/order-tracking/{order}/latest
     *
     * Yang dikembalikan adalah event siklus order terbaru (dibuat → diterima →
     * terdistribusi → dipinjam → dipindah → dikembalikan). Untuk order yang sudah
     * didistribusikan — satu-satunya keadaan yang dipakai kedua modal itu — event
     * inilah yang juga menempati posisi terakhir pada timeline penuh, karena
     * seluruh tahap pipeline CSSD terjadi sebelum alat diantar.
     */
    public function latest(Order $order): JsonResponse
    {
        // Pinjam-alih membuat beberapa order berbagi satu nomor transaksi; timeline
        // mereka satu rangkaian, jadi event terbarunya dicari lintas rantai — sama
        // seperti timeline penuh.
        $orderIds = $order->code_transaction
            ? Order::where('code_transaction', $order->code_transaction)->pluck('id')
            : collect([$order->id]);

        $query = OrderEvent::whereIn('order_id', $orderIds);

        $event = (clone $query)
            ->with('room:id,name')
            ->orderByDesc('created_at')
            // Beberapa event bisa tercatat pada detik yang sama (mis. diterima &
            // diproses) — id jadi penentu urutan terakhirnya.
            ->orderByDesc('id')
            ->first();

        return $this->success('Aktivitas terakhir order berhasil diambil.', [
            'event' => $event === null ? null : [
                'id' => $event->id,
                'type' => $event->type,
                'room' => $event->room?->name,
                'actor' => $event->actor,
                'borrowed_by' => $event->borrowed_by,
                'note' => $event->note,
                'created_at' => $event->created_at,
                // Tombol "Detail" tahap pipeline hanya ada di timeline penuh.
                'detail' => null,
            ],
            // Penanda apakah masih ada riwayat lain di baliknya — dasar munculnya
            // tombol "Tampilkan semua tracking". Sengaja bukan jumlah pasti: menghitung
            // baris timeline penuh berarti merakitnya, persis yang dihindari di sini.
            'has_more' => $event !== null && (
                (clone $query)->where('id', '!=', $event->id)->exists()
                // Ada unit → pipeline CSSD-nya pasti ikut tampil di timeline penuh.
                || OrderItem::whereIn('order_id', $orderIds)->exists()
            ),
        ]);
    }
}
