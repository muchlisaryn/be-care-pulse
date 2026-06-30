<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order produksi CSSD bersifat internal (CSSD memproses stok miliknya sendiri),
     * sehingga tidak terikat ke ruangan peminjam. Jadikan room_id nullable agar
     * batch produksi bisa dibuat tanpa ruangan.
     */
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable(false)->change();
        });
    }
};
