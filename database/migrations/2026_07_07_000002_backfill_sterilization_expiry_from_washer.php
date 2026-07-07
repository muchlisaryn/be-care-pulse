<?php

use App\Models\Sterilization;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Hitung ulang expiry_date semua batch sterilisasi yang sudah selesai agar
     * konsisten mengikuti batas steril mesin washer (master) — base (tgl kemas /
     * tgl sterilisasi) + sterile_shelf_life_days mesin washer, fallback default.
     * Data lama sebelumnya sebagian memakai default 7 hari yang tidak konsisten.
     */
    public function up(): void
    {
        Sterilization::query()
            ->where('status', Sterilization::STATUS_SELESAI)
            ->whereNotNull('sterilized_at')
            ->with('packagings.washing.washerMachine')
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
