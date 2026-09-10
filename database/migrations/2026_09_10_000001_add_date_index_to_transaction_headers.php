<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index `transaction_headers.date` untuk Laporan Nafsul
 * (LaporanController::rekapPembayaran):
 *
 *  - `WHERE date BETWEEN ? AND ? ORDER BY date, id` — daftar kuitansi layar
 *    kini diambil dari header lebih dulu. Tanpa index, tiap buka laporan
 *    berarti full table scan atas seluruh kuitansi sejak 2014.
 *
 * Sisa baris yang `date`-nya kosong diisi dulu dari `created_at`: filternya kini
 * membaca `date` apa adanya (tanpa COALESCE) supaya index ini terpakai, jadi
 * baris tanpa tanggal akan hilang dari laporan kalau dibiarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transaction_headers')
            ->whereNull('date')
            ->update(['date' => DB::raw('DATE(created_at)')]);

        Schema::table('transaction_headers', function (Blueprint $table) {
            $table->index('date', 'transaction_headers_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            $table->dropIndex('transaction_headers_date_index');
        });
    }
};
