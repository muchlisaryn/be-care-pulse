<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master satuan tidak lagi memakai kode. Migrasi pembuatnya sudah tanpa
     * `code`; ini hanya untuk database yang terlanjur dibuat dengan kolom itu.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('units', 'code')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        // Sengaja kosong — kolom `code` sudah tidak dipakai.
    }
};
