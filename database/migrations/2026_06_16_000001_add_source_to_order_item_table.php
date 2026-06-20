<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item', function (Blueprint $table) {
            // Asal unit pada order: `satuan` (dipilih per unit) atau `paket` (dari katalog paket).
            $table->string('source')->default('satuan')->after('instrument_stock_id');
            // Snapshot nama paket katalog saat order dibuat (null untuk satuan).
            $table->string('package_name')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('order_item', function (Blueprint $table) {
            $table->dropColumn(['source', 'package_name']);
        });
    }
};
