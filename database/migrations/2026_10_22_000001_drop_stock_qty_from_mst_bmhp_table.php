<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master BMHP tidak lagi menyimpan stok. Stok BMHP adalah kemasan hasil
     * produksi yang sudah steril di Gudang Steril BMHP (`storage_bmhp`), jadi
     * produksi & distribusi tidak lagi memotong/mengembalikan `stock_qty`.
     */
    public function up(): void
    {
        if (Schema::hasColumn('mst_bmhp', 'stock_qty')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->dropColumn('stock_qty');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('mst_bmhp', 'stock_qty')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->decimal('stock_qty', 12, 2)->default(0)->after('unit_id');
            });
        }
    }
};
