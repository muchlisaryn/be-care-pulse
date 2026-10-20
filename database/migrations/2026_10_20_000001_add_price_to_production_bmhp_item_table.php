<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Harga satuan saat produksi dibuat (snapshot), supaya perubahan harga di
     * master BMHP / Paket BMHP tidak mengubah nilai produksi yang sudah lewat.
     * Satuan → `mst_bmhp.price`; paket → total harga isi paket per 1 paket.
     */
    public function up(): void
    {
        if (Schema::hasColumn('production_bmhp_item', 'price')) {
            return;
        }

        Schema::table('production_bmhp_item', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->default(0)->after('unit_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('production_bmhp_item', 'price')) {
            return;
        }

        Schema::table('production_bmhp_item', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
