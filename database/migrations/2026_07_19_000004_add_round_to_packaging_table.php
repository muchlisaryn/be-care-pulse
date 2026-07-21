<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ronde pengemasan untuk `washing_code` yang sama: pengemasan pertama = 1,
     * pengemasan ulang (RPK, unit gagal steril) = 2, dst. Dipakai agar dua record
     * dengan washing yang sama terbaca urutannya tanpa menebak dari waktu.
     */
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->unsignedSmallInteger('round')->default(1)->after('reprocess_of');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropColumn('round');
        });
    }
};
