<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak masuknya order ke pipeline pemrosesan CSSD. Saat CSSD menekan
     * "Proses" pada order masuk, dicatat kapan (processed_at) & oleh siapa
     * (processed_by), lalu order pindah ke tahap Cleaning & Pengemasan.
     */
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('canceled_by');
            $table->string('processed_by')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn(['processed_at', 'processed_by']);
        });
    }
};
