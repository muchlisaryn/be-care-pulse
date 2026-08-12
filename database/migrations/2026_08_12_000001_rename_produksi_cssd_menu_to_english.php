<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Halaman /cssd/produksi sudah berbahasa Inggris, jadi nama menunya di grup
    // Transaksi ikut disamakan: "Produksi CSSD" → "CSSD Production".
    public function up(): void
    {
        DB::table('menus')->where('url', '/cssd/produksi')->update([
            'name' => 'CSSD Production',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', '/cssd/produksi')->update([
            'name' => 'Produksi CSSD',
            'updated_at' => now(),
        ]);
    }
};
