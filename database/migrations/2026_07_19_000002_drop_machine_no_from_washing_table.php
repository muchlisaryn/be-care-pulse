<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom `machine_no` dihapus — mesin washer sepenuhnya dirujuk lewat
     * `washer_machine_id` sejak kode/barcode WSH-NNN dibuang dari master.
     */
    public function up(): void
    {
        Schema::table('washing', function (Blueprint $table) {
            $table->dropColumn('machine_no');
        });
    }

    public function down(): void
    {
        Schema::table('washing', function (Blueprint $table) {
            // Nilai lama tidak dipulihkan — kolom dibuat ulang dalam keadaan kosong.
            $table->string('machine_no')->nullable()->after('washer_machine_id');
        });
    }
};
