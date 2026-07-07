<?php

use App\Models\Sterilization;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Masa simpan steril kini tidak lagi diambil dari mesin washer. Hitung ulang
     * expiry_date batch selesai ke aturan default: tgl sterilisasi + masa simpan
     * default (STERILE_SHELF_LIFE_DAYS). Operator tetap bisa menimpanya manual saat
     * validasi ke depan. Menggantikan backfill berbasis washer sebelumnya.
     */
    public function up(): void
    {
        Sterilization::query()
            ->where('status', Sterilization::STATUS_SELESAI)
            ->whereNotNull('sterilized_at')
            ->chunkById(200, function ($batches) {
                foreach ($batches as $batch) {
                    $batch->expiry_date = $batch->computeExpiryDate();
                    $batch->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Backfill data tak dapat dikembalikan ke nilai lama.
    }
};
