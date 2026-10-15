<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah menu "Produksi BMHP" (/cssd/production-bmhp) di dalam grup "Transaksi"
     * CSSD, tepat di bawah menu "Storage Steril" (Gudang Steril), lalu beri akses
     * ke seluruh authority yang ada.
     */
    public function up(): void
    {
        // Cari parent grup "Transaksi" CSSD (title_menu_id = CSSD)
        $parent = DB::table('menus')
            ->where('name', 'Transaksi')
            ->whereNull('parent_id')
            ->whereNotNull('title_menu_id')
            ->first();

        if (! $parent) {
            $parent = DB::table('menus')
                ->where('name', 'Transaksi')
                ->whereNull('parent_id')
                ->first();
        }

        if (! $parent) {
            return;
        }

        // Cari urutan Storage Steril
        $storageSteril = DB::table('menus')
            ->where('parent_id', $parent->id)
            ->where(function ($q) {
                $q->where('url', '/cssd/storage-steril')
                    ->orWhere('name', 'like', '%Storage Steril%')
                    ->orWhere('name', 'like', '%Gudang Steril%');
            })
            ->first();

        $targetSort = $storageSteril ? ($storageSteril->sort_order + 1) : 3;

        $existing = DB::table('menus')->where('url', '/cssd/production-bmhp')->first();

        if ($existing) {
            // Pastikan posisinya di bawah Storage Steril & namanya "Produksi BMHP"
            DB::table('menus')->where('id', $existing->id)->update([
                'name' => 'Produksi BMHP',
                'parent_id' => $parent->id,
                'title_menu_id' => $parent->title_menu_id,
                'sort_order' => $targetSort,
                'updated_at' => now(),
            ]);
            $menuId = $existing->id;
        } else {
            // Geser menu-menu setelahnya bila ada yang bertabrakan urutannya
            DB::table('menus')
                ->where('parent_id', $parent->id)
                ->where('sort_order', '>=', $targetSort)
                ->increment('sort_order');

            $menuId = DB::table('menus')->insertGetId([
                'title_menu_id' => $parent->title_menu_id,
                'parent_id' => $parent->id,
                'name' => 'Produksi BMHP',
                'url' => '/cssd/production-bmhp',
                'icon' => 'production',
                'sort_order' => $targetSort,
                'is_open' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Beri akses ke seluruh authority yang ada bila belum ada
        $existingAuthIds = DB::table('authority_menu')
            ->where('menu_id', $menuId)
            ->pluck('authority_id')
            ->all();

        $authorities = DB::table('authorities')->pluck('id');
        $toInsert = [];

        foreach ($authorities as $authId) {
            if (! in_array($authId, $existingAuthIds)) {
                $toInsert[] = [
                    'authority_id' => $authId,
                    'menu_id' => $menuId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (! empty($toInsert)) {
            DB::table('authority_menu')->insert($toInsert);
        }
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('url', '/cssd/production-bmhp')->first();
        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }
    }
};
