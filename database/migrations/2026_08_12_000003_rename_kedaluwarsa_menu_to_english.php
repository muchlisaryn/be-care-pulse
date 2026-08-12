<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Halaman /cssd/kedaluwarsa sudah berbahasa Inggris, jadi nama menunya di grup
    // Monitoring ikut disamakan: "Alat Kedaluwarsa Steril" → "Sterile Expiry".
    public function up(): void
    {
        DB::table('menus')->where('url', '/cssd/kedaluwarsa')->update([
            'name' => 'Sterile Expiry',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', '/cssd/kedaluwarsa')->update([
            'name' => 'Alat Kedaluwarsa Steril',
            'updated_at' => now(),
        ]);
    }
};
