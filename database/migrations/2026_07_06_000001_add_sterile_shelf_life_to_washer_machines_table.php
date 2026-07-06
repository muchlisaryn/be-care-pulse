<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batas steril per mesin washer: `sterile_shelf_life_days` = masa simpan steril
     * (hari) yang menentukan tanggal kedaluwarsa. Alat yang dicuci di mesin ini
     * memakai nilai tersebut untuk menghitung expiry pada tahap sterilisasi.
     */
    public function up(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->unsignedInteger('sterile_shelf_life_days')->nullable()->after('max_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->dropColumn('sterile_shelf_life_days');
        });
    }
};
