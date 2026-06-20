<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            // Nama orang yang mengembalikan instrumen (teks bebas, diisi saat pengembalian).
            $table->string('returned_by')->nullable()->after('return_actual_date');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn('returned_by');
        });
    }
};
