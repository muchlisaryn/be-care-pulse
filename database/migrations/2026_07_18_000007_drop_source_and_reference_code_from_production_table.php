<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sisa rancangan awal yang tak pernah terpakai. `source` dimaksudkan memisahkan
     * batch `internal` dari `reprocessing` (instrumen order yang dikembalikan lalu
     * diproses ulang) dan `reference_code` menyimpan kode order asalnya — tapi alur
     * reprocessing tidak pernah dibangun: semua batch dibuat `internal` dan
     * `reference_code` selalu null. Menyusul penghapusan kolom `status`
     * (2026_07_18_000005).
     */
    public function up(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->dropColumn(['source', 'reference_code']);
        });
    }

    public function down(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->string('source')->default('internal')->after('code');
            $table->string('reference_code')->nullable()->index()->after('source');
        });
    }
};
