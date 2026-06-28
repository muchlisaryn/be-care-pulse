<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hapus menu "Sterilisasi" (/cssd/sterilisasi) beserta relasi otoritasnya.
     * Tahap sterilisasi kini menjadi tab di halaman Tracking Order
     * (/cssd/monitoring?tab=sterilization), jadi menu mandiri tidak diperlukan lagi.
     */
    public function up(): void
    {
        $menu = DB::table('menus')->where('url', '/cssd/sterilisasi')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }

    public function down(): void
    {
        if (DB::table('menus')->where('url', '/cssd/sterilisasi')->exists()) {
            return;
        }

        $cssd = DB::table('title_menuses')->where('title', 'Cssd')->first();
        $parent = DB::table('menus')
            ->where('name', 'Transaksi')
            ->where('title_menu_id', $cssd?->id)
            ->whereNull('parent_id')
            ->first();

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $cssd?->id,
            'parent_id' => $parent?->id,
            'name' => 'Sterilisasi',
            'url' => '/cssd/sterilisasi',
            'icon' => 'shield-check',
            'sort_order' => 1,
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
