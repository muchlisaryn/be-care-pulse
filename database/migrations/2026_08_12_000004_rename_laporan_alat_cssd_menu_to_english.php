<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Halaman /cssd/laporan sudah berbahasa Inggris, jadi nama menunya di grup
    // Monitoring ikut disamakan: "Laporan Alat CSSD" → "CSSD Instrument Report".
    public function up(): void
    {
        DB::table('menus')->where('url', '/cssd/laporan')->update([
            'name' => 'CSSD Instrument Report',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', '/cssd/laporan')->update([
            'name' => 'Laporan Alat CSSD',
            'updated_at' => now(),
        ]);
    }
};
