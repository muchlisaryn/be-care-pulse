<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi packaging pada sterilisasi dipindah dari header ke DETAIL.
     *
     * `sterilizations.packaging_code` hanya menyimpan SATU kode padahal satu batch
     * bisa menggabungkan banyak packaging — sudah menyesatkan sejak batch gabungan
     * ada. Gantinya `sterilization_items.packaging_barcode`: tiap unit membawa nomor
     * label kemasannya sendiri, sehingga saat sebagian unit gagal steril ketahuan
     * persis label mana yang harus dikemas ulang.
     */
    public function up(): void
    {
        Schema::table('sterilization_items', function (Blueprint $table) {
            $table->string('packaging_barcode')->nullable()->after('instrument_stock_id');
            $table->index('packaging_barcode');
        });

        // Isi baris lama: cocokkan unit batch dengan packaging_item milik PKG yang
        // tergabung ke batch tersebut (packaging.sterilization_id).
        DB::statement('
            UPDATE sterilization_items si
            JOIN sterilizations s ON s.id = si.sterilization_id
            JOIN packaging p ON p.sterilization_id = s.id
            JOIN packaging_item pi ON pi.packaging_id = p.id
                 AND pi.instrument_stock_id = si.instrument_stock_id
            SET si.packaging_barcode = pi.barcode_no
        ');

        Schema::table('sterilizations', function (Blueprint $table) {
            $table->dropColumn('packaging_code');
        });
    }

    public function down(): void
    {
        Schema::table('sterilizations', function (Blueprint $table) {
            // Nilai lama tidak dipulihkan — kolom dibuat ulang dalam keadaan kosong.
            $table->string('packaging_code')->nullable()->after('code');
        });

        Schema::table('sterilization_items', function (Blueprint $table) {
            $table->dropIndex(['packaging_barcode']);
            $table->dropColumn('packaging_barcode');
        });
    }
};
