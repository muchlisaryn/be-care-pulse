<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot identitas unit saat dikunci ke batch produksi: kode unit fisik
     * (instrument_stocks.code) & nama instrumennya. Disalin, BUKAN dibaca lewat
     * relasi, supaya perubahan data master di kemudian hari (rename instrumen,
     * kode unit diubah, unit dihapus) tidak ikut mengubah riwayat batch lama.
     */
    public function up(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->string('kode_instrumen')->nullable()->after('instrument_stock_id');
            $table->string('name')->nullable()->after('kode_instrumen');
        });

        // Isi baris lama dari master saat ini — nilai terbaik yang tersedia.
        DB::statement('
            UPDATE production_item pi
            JOIN instrument_stocks s ON s.id = pi.instrument_stock_id
            LEFT JOIN instruments i ON i.id = s.instrument_id
            SET pi.kode_instrumen = s.code,
                pi.name = i.name
        ');
    }

    public function down(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->dropColumn(['kode_instrumen', 'name']);
        });
    }
};
