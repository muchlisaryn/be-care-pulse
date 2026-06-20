<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // - Halaman "Scan & Tracking" dihapus → hapus menunya (+ pivot otoritas).
    // - Menu "Monitoring" (/cssd/monitoring) → rename "Tracking Order" dan jadikan
    //   menu mandiri langsung di bawah title "Cssd" (parent_id = null).
    public function up(): void
    {
        // Hapus menu Scan & Tracking beserta relasi otoritasnya.
        $scan = DB::table('menus')->where('url', '/cssd/scan')->first();
        if ($scan) {
            DB::table('authority_menu')->where('menu_id', $scan->id)->delete();
            DB::table('menus')->where('id', $scan->id)->delete();
        }

        // Rename + jadikan top-level di bawah title "Cssd".
        $cssd = DB::table('title_menuses')->where('title', 'Cssd')->first();
        DB::table('menus')->where('url', '/cssd/monitoring')->update([
            'name' => 'Tracking Order',
            'parent_id' => null,
            'title_menu_id' => $cssd?->id,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Kembalikan nama menu; struktur grup lama tidak dipulihkan otomatis.
        DB::table('menus')->where('url', '/cssd/monitoring')->update([
            'name' => 'Monitoring',
            'updated_at' => now(),
        ]);
    }
};
