<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu "Laporan" (/nafsul/laporan) di dalam title "Nafsul", lalu diberi akses
 * ke semua authority.
 *
 * Ditaruh sebaris dengan "Transaksi", bukan di title "Master Data": laporan
 * membaca hasil pekerjaan harian, bukan mengatur data acuan.
 */
return new class extends Migration
{
    private const URL = '/nafsul/laporan';

    private const TITLE = 'Nafsul';

    public function up(): void
    {
        // Migrasi ini pernah dijalankan / menunya dibuat manual.
        if (DB::table('menus')->where('url', self::URL)->exists()) {
            return;
        }

        $titleId = DB::table('title_menuses')->where('title', self::TITLE)->value('id');

        // Fresh migrate: title-nya dibuat migrasi `add_nafsul_transaksi_menu`
        // yang berjalan lebih dulu, jadi keadaan ini praktis tidak terjadi.
        // Tetap dijaga agar migrasi tidak membuat menu mengambang tanpa title.
        if (! $titleId) {
            return;
        }

        // Setelah menu Nafsul yang sudah ada, supaya urutan yang sudah
        // disepakati (Transaksi di atas) tidak tergeser.
        $urutan = (int) DB::table('menus')
            ->where('title_menu_id', $titleId)
            ->whereNull('parent_id')
            ->max('sort_order') + 1;

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $titleId,
            'parent_id' => null,
            'name' => 'Laporan',
            'url' => self::URL,
            // Nama ikon Lucide; sidebar memetakannya lewat ICON_MAP dan jatuh
            // ke lingkaran kosong bila namanya tidak dikenal di sana.
            'icon' => 'file-text',
            'sort_order' => $urutan,
            'is_open' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tanpa baris authority_menu, menunya ada di database tapi tidak pernah
        // muncul di sidebar siapa pun — sidebar dibangun dari menu milik
        // authority pengguna, bukan dari seluruh isi tabel menus.
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
        $menu = DB::table('menus')->where('url', self::URL)->first();

        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }

        // Title TIDAK ikut dihapus: menu "Transaksi" masih bernaung di bawahnya,
        // dan migrasi yang membuatnya (add_nafsul_transaksi_menu) yang berhak
        // membereskannya saat ia sendiri di-rollback.
    }
};
