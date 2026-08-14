<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sub-menu "Ubah Kata Sandi" (/pengaturan/kata-sandi) di bawah menu Pengaturan.
 *
 * Sebelumnya form ganti kata sandi menumpang di halaman Pengaturan → Profil, jadi
 * tidak punya alamat sendiri dan tidak bisa ditautkan langsung. Sub-navigasi
 * Pengaturan dibangun dari ANAK menu ini (lihat pengaturan/layout.tsx), sehingga
 * halaman baru cukup didaftarkan di sini.
 *
 * Idempotent — aman dijalankan berulang pada DB lama maupun baru.
 *
 * Jalankan: php artisan db:seed --class=UbahKataSandiMenuSeeder
 */
class UbahKataSandiMenuSeeder extends Seeder
{
    private const URL = '/pengaturan/kata-sandi';

    public function run(): void
    {
        $parent = Menu::where('url', '/pengaturan')->whereNull('parent_id')->first();

        if (! $parent) {
            $this->command?->warn('Menu induk "/pengaturan" belum ada — jalankan PengaturanMenuSeeder dulu.');

            return;
        }

        $menu = Menu::firstOrCreate(
            ['url' => self::URL],
            [
                'title_menu_id' => $parent->title_menu_id,
                'parent_id' => $parent->id,
                'name' => 'Ubah Kata Sandi',
                'icon' => 'key',
                // Ditaruh sesudah sub-menu yang sudah ada.
                'sort_order' => (int) Menu::where('parent_id', $parent->id)->max('sort_order') + 1,
                'is_open' => false,
                'open_sidebar' => false,
            ],
        );

        // Rapikan bila barisnya sudah ada dari percobaan sebelumnya tapi belum
        // menempel ke induk yang benar.
        if ($menu->parent_id !== $parent->id) {
            $menu->update(['parent_id' => $parent->id, 'title_menu_id' => $parent->title_menu_id]);
        }

        // Beri akses ke seluruh authority. Baris pivot yang sudah ada dilewati supaya
        // hak akses yang sengaja dicabut admin tidak dikembalikan diam-diam.
        $existing = DB::table('authority_menu')->where('menu_id', $menu->id)->pluck('authority_id');
        $rows = DB::table('authorities')->pluck('id')
            ->diff($existing)
            ->map(fn ($authorityId) => [
                'authority_id' => $authorityId,
                'menu_id' => $menu->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if (! empty($rows)) {
            DB::table('authority_menu')->insert($rows);
        }

        $this->command?->info('Menu "Ubah Kata Sandi" ('.self::URL.') siap.');
    }
}
