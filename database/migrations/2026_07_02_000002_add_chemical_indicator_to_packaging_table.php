<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor lot/batch indikator kimia internal yang dimasukkan ke dalam kemasan
     * pada tahap Inspection & Packaging (dicatat saat "Selesai Packaging").
     */
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->string('chemical_indicator')->nullable()->after('operator');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropColumn('chemical_indicator');
        });
    }
};
