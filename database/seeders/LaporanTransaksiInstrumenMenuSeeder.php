<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tambah menu "Laporan Transaksi Instrumen" (/cssd/laporan-transaksi-instrumen) ke
 * grup "Monitoring" (title "Cssd") lalu beri akses ke seluruh authority.
 * Idempotent — aman dijalankan berulang.
 *
 * Jalankan: php artisan db:seed --class=LaporanTransaksiInstrumenMenuSeeder
 */
class LaporanTransaksiInstrumenMenuSeeder extends Seeder
{
    private const URL = '/cssd/laporan-transaksi-instrumen';

    /** Posisi di grup Monitoring: setelah "Laporan Alat CSSD" (2). */
    private const SORT_ORDER = 3;

    public function run(): void
    {
        // Sudah ada → tidak perlu dibuat lagi.
        if (Menu::where('url', self::URL)->exists()) {
            return;
        }

        // Parent = grup "Monitoring" di bawah title "Cssd". Title ikut dicocokkan
        // karena nama grup bisa dipakai title lain.
        $title = DB::table('title_menuses')->where('title', 'Cssd')->first();
        $parent = Menu::where('name', 'Monitoring')
            ->when($title, fn ($q) => $q->where('title_menu_id', $title->id))
            ->whereNull('parent_id')
            ->first();

        if (! $parent) {
            $this->command?->warn('Grup "Monitoring" belum ada. Jalankan MenuSeeder dulu.');

            return;
        }

        // Disisipkan di tengah grup → geser sibling di posisi ini & sesudahnya.
        Menu::where('parent_id', $parent->id)
            ->where('sort_order', '>=', self::SORT_ORDER)
            ->increment('sort_order');

        $menu = Menu::create([
            'title_menu_id' => $parent->title_menu_id,
            'parent_id' => $parent->id,
            'name' => 'Laporan Transaksi Instrumen',
            'url' => self::URL,
            // Sudah terdaftar di ICON_MAP sidebar frontend.
            'icon' => 'list',
            'sort_order' => self::SORT_ORDER,
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

        $this->command?->info('Menu "Laporan Transaksi Instrumen" ('.self::URL.') berhasil ditambahkan.');
    }
}
