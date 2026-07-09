<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah title section "Pengaturan" + grup "Pengaturan" (collapsible) yang
     * berisi submenu "Master Printer" (/pengaturan/master-printer), lalu beri
     * akses ke seluruh authority. Idempotent untuk DB lama; instalasi baru
     * memakai TitleMenuSeeder + MenuSeeder sebagai sumber kebenaran.
     */
    public function up(): void
    {
        // Fresh install (seeder belum jalan) → biarkan seeder yang mengisi agar
        // tidak dobel dengan MenuSeeder.
        if (DB::table('authorities')->count() === 0) {
            return;
        }
        if (DB::table('menus')->where('url', '/pengaturan/master-printer')->exists()) {
            return;
        }

        // Title section "Pengaturan" (buat bila belum ada).
        $titleId = DB::table('title_menuses')->where('title', 'Pengaturan')->value('id');
        if (! $titleId) {
            $maxSort = (int) DB::table('title_menuses')->max('sort_order');
            $titleId = DB::table('title_menuses')->insertGetId([
                'title' => 'Pengaturan',
                'sort_order' => $maxSort + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Grup "Pengaturan" (parent, tanpa url — hanya penampung submenu).
        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengaturan')
            ->where('title_menu_id', $titleId)
            ->value('id');
        if (! $parentId) {
            $parentId = DB::table('menus')->insertGetId([
                'title_menu_id' => $titleId,
                'parent_id' => null,
                'name' => 'Pengaturan',
                'url' => null,
                'icon' => 'settings',
                'sort_order' => 1,
                'is_open' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Submenu "Master Printer".
        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $titleId,
            'parent_id' => $parentId,
            'name' => 'Master Printer',
            'url' => '/pengaturan/master-printer',
            'icon' => 'printer',
            'sort_order' => 1,
            'is_open' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Beri akses grup + submenu ke seluruh authority yang ada.
        $rows = [];
        foreach (DB::table('authorities')->pluck('id') as $authorityId) {
            foreach ([$parentId, $menuId] as $mid) {
                $rows[] = [
                    'authority_id' => $authorityId,
                    'menu_id' => $mid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if (! empty($rows)) {
            DB::table('authority_menu')->insert($rows);
        }
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')
            ->where('url', '/pengaturan/master-printer')
            ->pluck('id')
            ->all();

        $parent = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengaturan')
            ->first();
        if ($parent) {
            $menuIds[] = $parent->id;
        }

        if (! empty($menuIds)) {
            DB::table('authority_menu')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }

        // Hapus title "Pengaturan" bila tak ada menu lain yang memakainya.
        $title = DB::table('title_menuses')->where('title', 'Pengaturan')->first();
        if ($title && ! DB::table('menus')->where('title_menu_id', $title->id)->exists()) {
            DB::table('title_menuses')->where('id', $title->id)->delete();
        }
    }
};
