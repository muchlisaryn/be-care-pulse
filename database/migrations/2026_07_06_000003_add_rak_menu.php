<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah menu "Rak" (/master/rak) di dalam grup "Master CSSD" pada title
     * "Master Data", lalu beri akses ke semua authority. Master ini menjadi
     * sumber pilihan lokasi rak saat "Simpan ke Gudang".
     */
    public function up(): void
    {
        if (DB::table('menus')->where('url', '/master/rak')->exists()) {
            return;
        }

        // Parent = grup "Master CSSD" di bawah title "Master Data".
        $parent = DB::table('menus')
            ->where('name', 'Master CSSD')
            ->whereNull('parent_id')
            ->first();

        // Fresh migrate: grup belum dibuat (seeder jalan setelah migrasi).
        // Lewati agar tidak membuat menu "mengambang" — MenuSeeder yang menjadi
        // sumber kebenaran untuk instalasi baru.
        if (! $parent) {
            return;
        }

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $parent?->title_menu_id,
            'parent_id' => $parent?->id,
            'name' => 'Rak',
            'url' => '/master/rak',
            'icon' => 'archive',
            'sort_order' => 6,
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
        $menu = DB::table('menus')->where('url', '/master/rak')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }
};
