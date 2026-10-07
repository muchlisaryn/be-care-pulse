<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Paket BMHP kini satu halaman dengan master BMHP (tab di /master/bmhp), jadi
     * menu tersendirinya (/master/paket-bmhp) dicabut beserta hak aksesnya.
     */
    public function up(): void
    {
        $menu = DB::table('menus')->where('url', '/master/paket-bmhp')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }

    /** Pasang lagi menunya di akhir grup "Master CSSD", dengan akses untuk semua authority. */
    public function down(): void
    {
        if (DB::table('menus')->where('url', '/master/paket-bmhp')->exists()) {
            return;
        }

        $parent = DB::table('menus')
            ->where('name', 'Master CSSD')
            ->whereNull('parent_id')
            ->first();

        if (! $parent) {
            return;
        }

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $parent->title_menu_id,
            'parent_id' => $parent->id,
            'name' => 'BMHP Package',
            'url' => '/master/paket-bmhp',
            'icon' => null,
            'sort_order' => (int) DB::table('menus')->where('parent_id', $parent->id)->max('sort_order') + 1,
            'is_open' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
};
