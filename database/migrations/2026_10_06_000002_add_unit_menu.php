<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah menu "Unit" (/master/satuan) di dalam grup "Master CSSD", lalu beri
     * akses ke semua authority. Namanya berbahasa Inggris seperti menu master
     * lainnya — tampilan Indonesianya ("Satuan") diurus glosarium di frontend.
     */
    public function up(): void
    {
        if (DB::table('menus')->where('url', '/master/satuan')->exists()) {
            return;
        }

        // Parent = grup "Master CSSD".
        $parent = DB::table('menus')
            ->where('name', 'Master CSSD')
            ->whereNull('parent_id')
            ->first();

        // Fresh migrate: grupnya belum ada — dilewati, sama seperti menu master lain.
        if (! $parent) {
            return;
        }

        // Ditaruh paling akhir di grupnya.
        $sortOrder = (int) DB::table('menus')->where('parent_id', $parent->id)->max('sort_order') + 1;

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $parent->title_menu_id,
            'parent_id' => $parent->id,
            'name' => 'Unit',
            'url' => '/master/satuan',
            'icon' => null,
            'sort_order' => $sortOrder,
            'is_open' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Beri akses ke seluruh authority yang ada.
        $rows = DB::table('authorities')->pluck('id')->map(fn ($authorityId) => [
            'authority_id' => $authorityId,
            'menu_id' => $menuId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (! empty($rows)) {
            DB::table('authority_menu')->insert($rows);
        }
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('url', '/master/satuan')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }
};
