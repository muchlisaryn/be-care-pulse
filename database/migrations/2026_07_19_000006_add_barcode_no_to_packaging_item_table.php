<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor barcode yang BENAR-BENAR tercetak di label kemasan: gabungan tanpa spasi
     * dari prefix + kode packaging + nomor set, mis. `PKG260719011`. Disimpan sebagai
     * snapshot supaya hasil scan bisa dicari langsung, tanpa merangkai ulang
     * packaging → washing → production_item.
     *
     * SENGAJA TIDAK UNIK: satu label mewakili satu SET, sedangkan packaging_item
     * adalah per unit — seluruh unit dalam satu set berbagi barcode_no yang sama.
     * Diberi index biasa agar pencarian hasil scan tetap cepat.
     */
    public function up(): void
    {
        Schema::table('packaging_item', function (Blueprint $table) {
            $table->string('barcode_no')->nullable()->after('package_name');
            $table->index('barcode_no');
        });

        // Isi baris lama dari relasi: packaging (prefix + code) + production_item
        // (package_no) yang dicocokkan lewat instrument_stock_id.
        DB::statement("
            UPDATE packaging_item pi
            JOIN packaging p ON p.id = pi.packaging_id
            JOIN washing w ON w.code = p.washing_code
            JOIN production pr ON pr.code = w.production_code
            JOIN production_item prd ON prd.production_id = pr.id
                 AND prd.instrument_stock_id = pi.instrument_stock_id
            SET pi.barcode_no = CONCAT(p.prefix, p.code, COALESCE(prd.package_no, ''))
        ");
    }

    public function down(): void
    {
        Schema::table('packaging_item', function (Blueprint $table) {
            $table->dropIndex(['barcode_no']);
            $table->dropColumn('barcode_no');
        });
    }
};
