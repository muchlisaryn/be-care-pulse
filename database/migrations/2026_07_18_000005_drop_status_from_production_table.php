<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahap Produksi tidak punya status: batch dibuat & unit dikunci dalam satu
     * aksi, jadi tidak pernah ada keadaan "sedang berjalan" yang perlu dicatat.
     * Kolom ini selalu diisi nilai yang sama saat create dan tidak pernah dibaca
     * sebagai penentu alur (hanya ditampilkan di timeline tracking unit).
     */
    public function up(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->string('status')->default('diproses')->after('note');
        });
    }
};
