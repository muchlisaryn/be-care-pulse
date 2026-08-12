<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Halaman /cssd/distribusi sudah berbahasa Inggris, jadi nama menunya di grup
    // Transaksi ikut disamakan: "Distribusi BMHP" → "BMHP Distribution".
    public function up(): void
    {
        DB::table('menus')->where('url', '/cssd/distribusi')->update([
            'name' => 'BMHP Distribution',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', '/cssd/distribusi')->update([
            'name' => 'Distribusi BMHP',
            'updated_at' => now(),
        ]);
    }
};
