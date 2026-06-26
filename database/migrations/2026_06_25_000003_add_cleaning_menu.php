<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah menu "Cleaning & Pengemasan" (/cssd/cleaning) di dalam grup
     * Transaksi pada title "Cssd", lalu beri akses ke semua authority
     * (administrator sudah punya semua menu, ini menyamakan untuk authority lain).
     */
    public function up(): void
    {
        if (DB::table('menus')->where('url', '/cssd/cleaning')->exists()) {
            return;
        }

        $cssd = DB::table('title_menuses')->where('title', 'Cssd')->first();

        // Parent = grup "Transaksi" di bawah title Cssd.
        $parent = DB::table('menus')
            ->where('name', 'Transaksi')
            ->where('title_menu_id', $cssd?->id)
            ->whereNull('parent_id')
            ->first();

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $cssd?->id,
            'parent_id' => $parent?->id,
            'name' => 'Cleaning & Pengemasan',
            'url' => '/cssd/cleaning',
            'icon' => 'droplets',
            'sort_order' => 5,
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
        $menu = DB::table('menus')->where('url', '/cssd/cleaning')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }
};
