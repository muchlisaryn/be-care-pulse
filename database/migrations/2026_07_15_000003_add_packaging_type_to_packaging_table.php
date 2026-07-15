<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis kemasan (linen | pouch | container) yang dipilih operator saat
     * "Selesai Pengemasan". Pilihan ini yang menentukan masa simpan steril →
     * `expiry_date` (lihat Packaging::PACKAGING_TYPES). Nullable karena batch
     * lama dikemas sebelum field ini ada.
     */
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->string('packaging_type')->nullable()->after('chemical_indicator');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropColumn('packaging_type');
        });
    }
};
