<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ubah "Pengaturan" menjadi menu tunggal (langsung ke /pengaturan) — seperti
     * Dashboard, tanpa submenu. Sub-navigasi (mis. Master Printer) dipindah ke
     * sidebar kedua di dalam area Pengaturan (frontend), bukan lagi submenu DB.
     */
    public function up(): void
    {
        // Hapus submenu "Master Printer" dari sidebar utama (+ grant-nya).
        $child = DB::table('menus')->where('url', '/pengaturan/master-printer')->first();
        if ($child) {
            DB::table('authority_menu')->where('menu_id', $child->id)->delete();
            DB::table('menus')->where('id', $child->id)->delete();
        }

        // Jadikan grup "Pengaturan" sebagai menu langsung (punya url, tanpa anak).
        DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengaturan')
            ->update(['url' => '/pengaturan', 'icon' => 'settings', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Kembalikan: "Pengaturan" jadi grup (url null) + submenu "Master Printer".
        $parent = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengaturan')
            ->first();
        if (! $parent) {
            return;
        }

        DB::table('menus')->where('id', $parent->id)->update(['url' => null, 'updated_at' => now()]);

        if (! DB::table('menus')->where('url', '/pengaturan/master-printer')->exists()) {
            $childId = DB::table('menus')->insertGetId([
                'title_menu_id' => $parent->title_menu_id,
                'parent_id' => $parent->id,
                'name' => 'Master Printer',
                'url' => '/pengaturan/master-printer',
                'icon' => 'printer',
                'sort_order' => 1,
                'is_open' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = DB::table('authorities')->pluck('id')->map(fn ($authorityId) => [
                'authority_id' => $authorityId,
                'menu_id' => $childId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();
            if (! empty($rows)) {
                DB::table('authority_menu')->insert($rows);
            }
        }
    }
};
