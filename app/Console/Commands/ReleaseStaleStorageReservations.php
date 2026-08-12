<?php

namespace App\Console\Commands;

use App\Models\InstrumentStorage;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Lepas reservasi gudang steril yang menempel pada order yang sudah tidak berjalan.
 *
 * `instrument_storages.order_id` diisi saat unit dialokasikan untuk sebuah order dan
 * seharusnya berakhir ketika unit benar-benar keluar rak (baris jadi `keluar`). Bila
 * ordernya batal / dihapus / sudah selesai sebelum itu, reservasinya tidak pernah
 * dilepas: unit hilang dari pool steril padahal fisiknya masih di rak — inilah stok
 * yang "terlihat ada tapi tidak bisa didistribusikan".
 *
 * Sejak pembatalan & penghapusan order melepasnya sendiri
 * (OrderController::releaseStorageReservations), command ini untuk membersihkan sisa
 * yang telanjur ada, dan sebagai jaring pengaman bila ada jalur lain yang terlewat.
 */
class ReleaseStaleStorageReservations extends Command
{
    protected $signature = 'storage:release-stale-reservations {--dry-run : Tampilkan saja, jangan ubah data}';

    protected $description = 'Lepas reservasi gudang steril milik order yang sudah tidak berjalan';

    public function handle(): int
    {
        // Order dianggap tidak berjalan bila dibatalkan, dihapus, atau tidak lagi
        // memegang unit yang belum kembali — dibaca dari jejak, bukan kolom status.
        $staleOrderIds = Order::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNotNull('canceled_at')
                ->orWhereNotNull('deleted_by')
                ->orWhereNotExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('order_item')
                    ->whereColumn('order_item.order_id', 'order.id')
                    ->where('order_item.is_returned', false)
                    ->whereNull('order_item.deleted_by')))
            ->pluck('id');

        $query = InstrumentStorage::withoutGlobalScopes()
            ->whereNull('deleted_by')
            // Hanya baris yang MASIH di rak: baris `keluar` adalah riwayat pengeluaran,
            // order_id-nya memang harus tetap menunjuk ordernya.
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->whereNotNull('order_id')
            ->whereIn('order_id', $staleOrderIds);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Tidak ada reservasi nyangkut. Pool steril bersih.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("{$count} baris gudang direservasi order yang sudah tidak berjalan (dry-run, tidak ada yang diubah).");

            $this->table(
                ['Order', 'Baris tertahan'],
                (clone $query)->selectRaw('order_id, COUNT(*) as total')
                    ->groupBy('order_id')
                    ->get()
                    ->map(fn ($r) => [
                        Order::withoutGlobalScopes()->find($r->order_id)?->code ?? "#{$r->order_id}",
                        $r->total,
                    ])
            );

            return self::SUCCESS;
        }

        $query->update(['order_id' => null, 'updated_by' => 'system:release-stale-reservations']);

        $this->info("{$count} baris gudang dilepas kembali ke pool steril.");

        return self::SUCCESS;
    }
}
