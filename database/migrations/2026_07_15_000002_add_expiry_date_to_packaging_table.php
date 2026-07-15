<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tgl kedaluwarsa steril kini ditetapkan operator saat "Selesai Pengemasan"
     * (setelah packaging, sebelum sterilisasi) — menggantikan batas steril per
     * mesin washer yang sudah dihapus. Nilai ini dipakai label barcode dan
     * diwariskan ke batch sterilisasi yang dibuat dari tray ini.
     */
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->after('packaged_at');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropColumn('expiry_date');
        });
    }
};
