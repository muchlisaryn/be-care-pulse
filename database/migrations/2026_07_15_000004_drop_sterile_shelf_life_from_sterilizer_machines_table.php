<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batas steril tidak lagi diatur per mesin. Masa simpan steril kini ditentukan
     * jenis kemasan yang dipilih operator saat "Selesai Pengemasan"
     * (Packaging::PACKAGING_TYPES) — melengkapi penghapusan kolom serupa di
     * washer_machines (2026_07_15_000001). Kolom ini tak pernah dipakai
     * perhitungan apa pun, murni CRUD.
     */
    public function up(): void
    {
        Schema::table('sterilizer_machines', function (Blueprint $table) {
            $table->dropColumn('sterile_shelf_life_days');
        });
    }

    public function down(): void
    {
        Schema::table('sterilizer_machines', function (Blueprint $table) {
            $table->unsignedInteger('sterile_shelf_life_days')->nullable()->after('duration_minutes');
        });
    }
};
