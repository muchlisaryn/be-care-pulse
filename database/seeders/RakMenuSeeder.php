<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tambah menu "Rak" (/master/rak) ke grup "Master CSSD" (title "Master Data")
 * lalu beri akses ke seluruh authority. Idempotent — aman dijalankan berulang.
 *
 * Jalankan: php artisan db:seed --class=RakMenuSeeder
 */
class RakMenuSeeder extends Seeder
{
    public function run(): void
    {
        // Sudah ada → tidak perlu dibuat lagi.
        if (Menu::where('url', '/master/rak')->exists()) {
            return;
        }

        // Parent = grup "Master CSSD" di bawah title "Master Data".
        $parent = Menu::where('name', 'Master CSSD')
            ->whereNull('parent_id')
            ->first();

        if (! $parent) {
            $this->command?->warn('Grup "Master CSSD" belum ada. Jalankan MenuSeeder dulu.');

            return;
        }

        $menu = Menu::create([
            'title_menu_id' => $parent->title_menu_id,
            'parent_id' => $parent->id,
            'name' => 'Rak',
            'url' => '/master/rak',
            'icon' => 'archive',
            'sort_order' => 6,
            'is_open' => false,
        ]);

        // Beri akses ke seluruh authority yang ada.
        $rows = DB::table('authorities')->pluck('id')->map(fn ($authorityId) => [
            'authority_id' => $authorityId,
            'menu_id' => $menu->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (! empty($rows)) {
            DB::table('authority_menu')->insert($rows);
        }

        $this->command?->info('Menu "Rak" (/master/rak) berhasil ditambahkan.');
    }
}
