<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah menu "Storage Steril" (/cssd/storage-steril) di grup Transaksi pada
     * title Cssd, antara Tracking Order & Distribusi BMHP. Beri akses ke semua
     * authority. Tahap 5 — penyimpanan unit steril ke rak gudang.
     */
    public function up(): void
    {
        if (DB::table('menus')->where('url', '/cssd/storage-steril')->exists()) {
            return;
        }

        $cssd = DB::table('title_menuses')->where('title', 'Cssd')->first();
        $parent = DB::table('menus')
            ->where('name', 'Transaksi')
            ->where('title_menu_id', $cssd?->id)
            ->whereNull('parent_id')
            ->first();

        // Fresh migrate: grup Transaksi belum ada (seeder jalan setelah migrasi).
        // Lewati agar tidak membuat menu mengambang — seeder sudah menambah item ini.
        if (! $parent) {
            return;
        }

        // Geser Distribusi BMHP agar Storage Steril menyelip sebelumnya.
        DB::table('menus')->where('url', '/cssd/distribusi')->update(['sort_order' => 4, 'updated_at' => now()]);

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $cssd?->id,
            'parent_id' => $parent?->id,
            'name' => 'Storage Steril',
            'url' => '/cssd/storage-steril',
            'icon' => 'warehouse',
            'sort_order' => 3,
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

    public function down(): void
    {
        $menu = DB::table('menus')->where('url', '/cssd/storage-steril')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
        DB::table('menus')->where('url', '/cssd/distribusi')->update(['sort_order' => 3, 'updated_at' => now()]);
    }
};
