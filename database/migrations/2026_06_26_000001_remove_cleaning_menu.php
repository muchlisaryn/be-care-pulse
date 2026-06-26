<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tahap Cleaning & Pengemasan kini jadi tab di halaman Tracking Order
     * (/cssd/monitoring), bukan menu tersendiri. Hapus menu /cssd/cleaning
     * beserta relasi otoritasnya.
     */
    public function up(): void
    {
        $menu = DB::table('menus')->where('url', '/cssd/cleaning')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }

    public function down(): void
    {
        // Tidak dipulihkan otomatis; menu lama dibuat ulang lewat seeder bila perlu.
    }
};
