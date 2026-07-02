<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asal unit saat disimpan (satuan/paket) + nama paket — didenormalisasi ke
     * baris gudang agar inventaris bisa dikelompokkan per paket tanpa join berat.
     */
    public function up(): void
    {
        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->string('source')->default('satuan')->after('instrument_stock_id');
            $table->string('package_name')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropColumn(['source', 'package_name']);
        });
    }
};
