<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah menu "Produksi CSSD" (/cssd/produksi) sebagai item PERTAMA grup
     * Transaksi pada title Cssd — awal lifecycle: CSSD memproses stok miliknya
     * sendiri ke antrean Cleaning. Item lain digeser turun. Akses ke semua authority.
     */
    private array $shift = [
        '/cssd/order/instrumen' => 2,
        '/cssd/monitoring' => 3,
        '/cssd/storage-steril' => 4,
        '/cssd/distribusi' => 5,
    ];

    public function up(): void
    {
        if (DB::table('menus')->where('url', '/cssd/produksi')->exists()) {
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

        // Geser item lain agar Produksi CSSD menempati urutan pertama.
        foreach ($this->shift as $url => $sort) {
            DB::table('menus')->where('url', $url)->update(['sort_order' => $sort, 'updated_at' => now()]);
        }

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $cssd?->id,
            'parent_id' => $parent?->id,
            'name' => 'Produksi CSSD',
            'url' => '/cssd/produksi',
            'icon' => 'factory',
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

    public function down(): void
    {
        $menu = DB::table('menus')->where('url', '/cssd/produksi')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }

        // Kembalikan urutan sebelumnya.
        $previous = [
            '/cssd/order/instrumen' => 1,
            '/cssd/monitoring' => 2,
            '/cssd/storage-steril' => 3,
            '/cssd/distribusi' => 4,
        ];
        foreach ($previous as $url => $sort) {
            DB::table('menus')->where('url', $url)->update(['sort_order' => $sort, 'updated_at' => now()]);
        }
    }
};
