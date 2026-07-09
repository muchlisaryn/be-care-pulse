<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Jadikan sub-halaman Pengaturan (Master Printer) sebagai CHILD dari menu
     * "Pengaturan" di master, agar sidebar kedua (sub-nav Pengaturan) terisi dari
     * data menu — bukan hardcode. Menu "Pengaturan" tetap punya url (/pengaturan)
     * sehingga di sidebar utama tampil sebagai satu link (anak disembunyikan).
     */
    public function up(): void
    {
        $parent = DB::table('menus')
            ->whereNull('parent_id')
            ->where('url', '/pengaturan')
            ->first();

        // Fresh install → seeder yang mengisi.
        if (! $parent) {
            return;
        }

        if (DB::table('menus')->where('url', '/pengaturan/master-printer')->exists()) {
            return;
        }

        $childId = DB::table('menus')->insertGetId([
            'title_menu_id' => $parent->title_menu_id,
            'parent_id' => $parent->id,
            'name' => 'Master Printer',
            'url' => '/pengaturan/master-printer',
            'icon' => 'printer',
            'sort_order' => 1,
            'is_open' => false,
            // Halaman di area Pengaturan → sidebar utama tetap collapse.
            'open_sidebar' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = DB::table('authorities')->pluck('id')->map(fn ($authorityId) => [
            'authority_id' => $authorityId,
            'menu_id' => $childId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();
        if (! empty($rows)) {
            DB::table('authority_menu')->insert($rows);
        }
    }

    public function down(): void
    {
        $child = DB::table('menus')->where('url', '/pengaturan/master-printer')->first();
        if ($child) {
            DB::table('authority_menu')->where('menu_id', $child->id)->delete();
            DB::table('menus')->where('id', $child->id)->delete();
        }
    }
};
