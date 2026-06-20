<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            // Jejak pembatalan order — terpisah dari soft delete (deleted_at/by).
            // Order yang dibatalkan tetap tersimpan & tampil sebagai riwayat
            // (status "dibatalkan"); hanya "hapus" yang mengisi deleted_at/by.
            $table->timestamp('canceled_at')->nullable()->after('returned_by');
            $table->string('canceled_by')->nullable()->after('canceled_at');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn(['canceled_at', 'canceled_by']);
        });
    }
};
