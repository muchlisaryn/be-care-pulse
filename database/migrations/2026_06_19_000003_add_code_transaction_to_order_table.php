<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            // Kode transaksi unik (mis. INV20260619001), digenerate saat order
            // diterima/dipinjamkan. Dipakai untuk barcode.
            $table->string('code_transaction')->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropUnique(['code_transaction']);
            $table->dropColumn('code_transaction');
        });
    }
};
