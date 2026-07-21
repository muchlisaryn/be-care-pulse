<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Melengkapi snapshot instrumen di production_item (kode & nama sudah lebih
     * dulu, lihat 000006) dengan fotonya. Yang disimpan adalah PATH relatif —
     * sama seperti kolom `instruments.image` — bukan URL absolut, supaya tidak
     * ikut basi bila host/domain aplikasi berubah. URL penuhnya dibentuk accessor
     * `image_url` di model ProductionItem.
     */
    public function up(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->string('image')->nullable()->after('name');
        });

        // Isi baris lama dari master saat ini — nilai terbaik yang tersedia.
        DB::statement('
            UPDATE production_item pi
            JOIN instrument_stocks s ON s.id = pi.instrument_stock_id
            LEFT JOIN instruments i ON i.id = s.instrument_id
            SET pi.image = i.image
        ');
    }

    public function down(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
