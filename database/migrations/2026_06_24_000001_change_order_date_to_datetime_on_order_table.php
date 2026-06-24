<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            // Ubah dari date → datetime agar jam saat peminjaman ikut tercatat.
            $table->dateTime('order_date')->change();
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->date('order_date')->change();
        });
    }
};
