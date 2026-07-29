<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kembalikan `instrument_stock_id` ke baris gudang, berdampingan dengan
 * `production_item_id`.
 *
 * Unit fisik dipakai hampir di semua query gudang (hitung stok, cek duplikat,
 * timeline, tarik-ulang produksi) sehingga menempuh JOIN ke production_item tiap
 * kali terlalu mahal & berisik. `source`/`package_name` TETAP dihapus — keduanya
 * memang identitas batch produksi, bukan identitas penempatan rak.
 *
 * Kolom ini turunan: diisi dari `production_item.instrument_stock_id` saat baris
 * dibuat, jadi tidak boleh diisi sendiri secara terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->foreignId('instrument_stock_id')->nullable()->after('production_item_id');
        });

        DB::statement(<<<'SQL'
            UPDATE instrument_storages s
            JOIN production_item pi ON pi.id = s.production_item_id
            SET s.instrument_stock_id = pi.instrument_stock_id
        SQL);

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->unsignedBigInteger('instrument_stock_id')->nullable(false)->change();
            $table->foreign('instrument_stock_id')->references('id')->on('instrument_stocks')->restrictOnDelete();
            $table->index(['instrument_stock_id', 'status']);
        });
    }

    public function down(): void
    {
        // FK dulu, baru index: MySQL menolak menghapus index yang masih menopang
        // sebuah foreign key (error 1553).
        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropForeign(['instrument_stock_id']);
        });

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropIndex(['instrument_stock_id', 'status']);
            $table->dropColumn('instrument_stock_id');
        });
    }
};
