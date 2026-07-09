<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\TitleMenus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tambah title "Pengaturan" + menu tunggal "Pengaturan" (/pengaturan) — seperti
 * Dashboard, tanpa submenu — lalu beri akses ke seluruh authority. Idempotent,
 * aman dijalankan berulang (untuk DB lama & baru). Sub-navigasi Pengaturan
 * (mis. Master Printer) ditangani sidebar kedua di frontend.
 *
 * Jalankan: php artisan db:seed --class=PengaturanMenuSeeder
 */
class PengaturanMenuSeeder extends Seeder
{
    public function run(): void
    {
        // Sudah ada → tidak perlu dibuat lagi.
        if (Menu::where('url', '/pengaturan')->exists()) {
            return;
        }

        // Title section "Pengaturan" (buat bila belum ada).
        $title = TitleMenus::firstOrCreate(
            ['title' => 'Pengaturan'],
            ['sort_order' => (int) TitleMenus::max('sort_order') + 1],
        );

        // Menu tunggal "Pengaturan" (punya url, tanpa anak). open_sidebar=false →
        // sidebar utama otomatis menutup saat halaman Pengaturan dibuka.
        $menu = Menu::firstOrCreate(
            ['name' => 'Pengaturan', 'parent_id' => null, 'title_menu_id' => $title->id],
            ['url' => '/pengaturan', 'icon' => 'settings', 'sort_order' => 1, 'is_open' => false, 'open_sidebar' => false],
        );
        // Pastikan url terisi bila record lama masih url null (grup lama).
        if ($menu->url !== '/pengaturan') {
            $menu->update(['url' => '/pengaturan', 'icon' => 'settings', 'open_sidebar' => false]);
        }

        // Sub-halaman Pengaturan sebagai anak (mengisi sidebar kedua dari data).
        $child = Menu::firstOrCreate(
            ['url' => '/pengaturan/master-printer'],
            [
                'title_menu_id' => $title->id,
                'parent_id' => $menu->id,
                'name' => 'Master Printer',
                'icon' => 'printer',
                'sort_order' => 1,
                'is_open' => false,
                'open_sidebar' => false,
            ],
        );

        // Beri akses menu induk + anak ke seluruh authority yang ada.
        $menuIds = [$menu->id, $child->id];
        $rows = [];
        foreach (DB::table('authorities')->pluck('id') as $authorityId) {
            foreach ($menuIds as $menuId) {
                $rows[] = [
                    'authority_id' => $authorityId,
                    'menu_id' => $menuId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (! empty($rows)) {
            // Cegah duplikat pivot.
            DB::table('authority_menu')->whereIn('menu_id', $menuIds)->delete();
            DB::table('authority_menu')->insert($rows);
        }

        $this->command?->info('Menu "Pengaturan" (/pengaturan) berhasil ditambahkan.');
    }
}
