<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk Laporan Transaksi (ReportController::transaksi):
 *
 *  - `order WHERE status = 'dikembalikan' AND order_date BETWEEN ? AND ?
 *     ORDER BY order_date DESC, id DESC` → index komposit (status, order_date).
 *  - `return_actual_date` di-index tersendiri untuk rekap "dikembalikan tanggal X".
 *
 * Tabel `order` sebelumnya hanya punya index PK, unique `code`, foreign key
 * (room_id, user_id), `code_transaction`, dan `deleted_user_id` — filter status +
 * tanggal pada laporan berarti full table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->index(['status', 'order_date'], 'order_status_order_date_index');
            $table->index('return_actual_date', 'order_return_actual_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropIndex('order_status_order_date_index');
            $table->dropIndex('order_return_actual_date_index');
        });
    }
};
