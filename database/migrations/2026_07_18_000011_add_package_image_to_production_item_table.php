<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto PAKET (instrument_catalogs.image) untuk unit ber-source `paket`. Selama
     * ini production_item hanya menyimpan nama paket sebagai teks, tanpa jejak ke
     * katalognya sama sekali — akibatnya baris paket tidak punya foto untuk
     * ditampilkan (foto instrumen penyusunnya bukan foto paketnya).
     *
     * Seperti kolom `image` (000010), yang disimpan adalah PATH relatif; URL penuh
     * dibentuk accessor `package_image_url` di model ProductionItem.
     */
    public function up(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->string('package_image')->nullable()->after('image');
        });

        // Backfill baris paket lama: cocokkan nama paket ke katalog yang masih ada.
        // Katalog yang sudah dihapus/di-rename tidak dapat dicocokkan → tetap null.
        DB::statement("
            UPDATE production_item pi
            JOIN instrument_catalogs c ON c.name = pi.package_name
            SET pi.package_image = c.image
            WHERE pi.source = 'paket'
        ");
    }

    public function down(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->dropColumn('package_image');
        });
    }
};
