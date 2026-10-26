<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master BMHP tidak lagi menyimpan stok (stoknya ada di Gudang Steril BMHP).
     * Migrasi pembuatnya sudah tanpa `stock_qty`; ini hanya untuk database yang
     * terlanjur dibuat dengan kolom itu.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('mst_bmhp', 'stock_qty')) {
            return;
        }

        Schema::table('mst_bmhp', function (Blueprint $table) {
            $table->dropColumn('stock_qty');
        });
    }

    public function down(): void
    {
        // Sengaja kosong — kolom `stock_qty` sudah tidak dipakai.
    }
};
