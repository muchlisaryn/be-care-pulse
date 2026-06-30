<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderWashing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Produksi CSSD — awal lifecycle pemrosesan. CSSD memproses stok alat miliknya
 * sendiri (tanpa order peminjam) langsung masuk antrean Cleaning. Membuat order
 * INTERNAL (room_id null, borrowed_by = "Produksi CSSD") berstatus `pencucian`,
 * sehingga mengalir ke pipeline yang ada: Cleaning → Packaging → Sterilization →
 * Storage. Unit fisik di-scan saat Packaging seperti order biasa.
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
                $order = Order::create([
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

                // Catatan pencucian kosong (diisi operator di menu Cleaning).
                $order->washing()->firstOrCreate([], [
                    'status' => OrderWashing::STATUS_DALAM_PROSES,
                ]);

                // Timeline: dibuat + langsung diproses ke Cleaning.
                OrderEvent::record(OrderEvent::TYPE_DIBUAT, $order, [
                    'note' => 'Batch produksi CSSD dibuat',
                ]);
                OrderEvent::record(OrderEvent::TYPE_DIPROSES, $order, [
                    'note' => 'Produksi masuk tahap Cleaning',
                ]);

                return $order;
            });

            $order->load(['requestItems.instrument', 'requestItems.catalog', 'washing']);

            return $this->success('Batch produksi berhasil dibuat & masuk tahap Cleaning.', $order, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
