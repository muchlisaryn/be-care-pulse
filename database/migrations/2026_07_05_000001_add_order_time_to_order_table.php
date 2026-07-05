<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            // Jam pinjam (wajib diisi di form) — dilengkapi terpisah dari order_date.
            // Nullable di level DB agar baris lama tetap valid; validasi request yang
            // menegakkan wajib-isi untuk order baru.
            $table->time('order_time')->nullable()->after('order_date');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn('order_time');
        });
    }
};
