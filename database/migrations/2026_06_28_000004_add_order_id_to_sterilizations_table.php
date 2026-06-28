<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tautkan batch sterilisasi ke order asalnya bila dibuat dari pipeline
     * (tab Sterilization). Memudahkan menampilkan batch yang menunggu validasi
     * per order & memvalidasi (Steril / Gagal) langsung dari tab.
     * Nullable: batch yang dibuat manual dari menu Sterilisasi tidak punya order.
     */
    public function up(): void
    {
        Schema::table('sterilizations', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('id')
                ->constrained('order')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sterilizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
