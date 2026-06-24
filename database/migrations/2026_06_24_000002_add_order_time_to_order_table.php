<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            // Pisahkan tanggal & jam peminjaman ke kolom berbeda:
            // order_date kembali bertipe date, jam disimpan terpisah di order_time.
            $table->date('order_date')->change();
            $table->time('order_time')->nullable()->after('order_date');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn('order_time');
            $table->dateTime('order_date')->change();
        });
    }
};
