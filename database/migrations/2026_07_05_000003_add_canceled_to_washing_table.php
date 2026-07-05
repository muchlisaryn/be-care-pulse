<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('washing', function (Blueprint $table) {
            // Jejak pembatalan batch cleaning (siapa & kapan) — batch tetap tersimpan
            // sebagai riwayat (tidak dihapus) dengan status `batal`.
            $table->string('canceled_by')->nullable()->after('completed_at');
            $table->timestamp('canceled_at')->nullable()->after('canceled_by');
        });
    }

    public function down(): void
    {
        Schema::table('washing', function (Blueprint $table) {
            $table->dropColumn(['canceled_by', 'canceled_at']);
        });
    }
};
