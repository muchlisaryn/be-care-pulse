<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isi `instrument_storages.removed_at` untuk baris yang sudah terlanjur ditandai keluar
 * tanpa jejak waktunya.
 *
 * ProductionController::closeStorageForReprocessed() dulu hanya menulis kolom `status`
 * saat menarik unit kembali ke produksi, tanpa `removed_at` (jalur order di
 * OrderController selalu menulis keduanya). Akibatnya baris tersebut mengaku "keluar"
 * hanya lewat `status`.
 *
 * Migration ini WAJIB jalan sebelum InstrumentStorage::sterilePool() berpindah dari
 * `status = tersimpan` ke penanda relasi/audit (`order_id` + `removed_at`) — kalau
 * tidak, baris-baris itu akan terbaca seolah masih di rak dan muncul lagi di gudang
 * steril padahal barangnya sedang diproses ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `updated_at` adalah bukti terbaik kapan baris itu ditandai keluar.
        DB::table('instrument_storages')
            ->where('status', 'keluar')
            ->whereNull('removed_at')
            ->update([
                'removed_at' => DB::raw('updated_at'),
                'updated_by' => 'system:backfill-removed-at',
            ]);
    }

    public function down(): void
    {
        // Hanya baris yang diisi migration ini yang dikosongkan lagi — ditandai lewat
        // `updated_by`, jadi jejak yang ditulis alur normal tidak ikut hilang.
        DB::table('instrument_storages')
            ->where('updated_by', 'system:backfill-removed-at')
            ->update(['removed_at' => null]);
    }
};
