<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batas steril tidak lagi diatur per mesin washer. Masa simpan steril kini
     * sepenuhnya mengikuti aturan default (Sterilization::STERILE_SHELF_LIFE_DAYS)
     * dan tetap bisa ditimpa manual operator saat validasi batch sterilisasi —
     * melanjutkan pembersihan yang dimulai backfill 2026_07_07_000003.
     */
    public function up(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->dropColumn('sterile_shelf_life_days');
        });
    }

    public function down(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->unsignedInteger('sterile_shelf_life_days')->nullable()->after('duration_minutes');
        });
    }
};
