<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rapikan menu "mengambang" (parent_id NULL, tanpa grup) yang terlanjur dibuat
     * oleh migrasi penambah menu saat dijalankan sebelum MenuSeeder (fresh migrate),
     * sehingga tampil tanpa grup di sidebar:
     *
     *  - "Mesin Washer" (/master/mesin-washer)   → pindahkan ke grup "Master CSSD"
     *                                               (title "Master Data").
     *  - "Storage Steril" (/cssd/storage-steril) → hapus yang mengambang (versi resmi
     *                                               sudah ada di grup CSSD › Transaksi).
     *  - "Produksi CSSD" (/cssd/produksi)        → hapus yang mengambang (versi resmi
     *                                               sudah ada di grup CSSD › Transaksi).
     */
    public function up(): void
    {
        // 1) Mesin Washer → grup Master CSSD (di bawah title "Master Data").
        $masterCssd = DB::table('menus')
            ->where('name', 'Master CSSD')
            ->whereNull('parent_id')
            ->first();

        $orphanWasher = DB::table('menus')
            ->where('url', '/master/mesin-washer')
            ->whereNull('parent_id')
            ->first();

        if ($masterCssd && $orphanWasher) {
            $alreadyGrouped = DB::table('menus')
                ->where('url', '/master/mesin-washer')
                ->where('parent_id', $masterCssd->id)
                ->exists();

            if ($alreadyGrouped) {
                // Sudah ada versi resmi di grup → buang yang mengambang.
                $this->deleteMenu($orphanWasher->id);
            } else {
                // Sambungkan menu mengambang ke grup Master CSSD.
                DB::table('menus')->where('id', $orphanWasher->id)->update([
                    'title_menu_id' => $masterCssd->title_menu_id,
                    'parent_id' => $masterCssd->id,
                    'sort_order' => 5,
                    'updated_at' => now(),
                ]);
            }
        }

        // 2) & 3) Hapus versi mengambang bila sudah ada versi resmi dalam grup.
        foreach (['/cssd/storage-steril', '/cssd/produksi'] as $url) {
            $grouped = DB::table('menus')
                ->where('url', $url)
                ->whereNotNull('parent_id')
                ->exists();

            if (! $grouped) {
                continue; // tak ada versi resmi → jangan hapus apa pun.
            }

            $orphans = DB::table('menus')
                ->where('url', $url)
                ->whereNull('parent_id')
                ->pluck('id');

            foreach ($orphans as $id) {
                $this->deleteMenu($id);
            }
        }
    }

    private function deleteMenu(int $menuId): void
    {
        DB::table('authority_menu')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }

    public function down(): void
    {
        // Pembersihan data lama tidak bisa dipulihkan otomatis; no-op.
    }
};
