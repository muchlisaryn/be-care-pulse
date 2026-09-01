<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu "Jasa Anggota" (/nafsul/jasa-anggota) di bawah title "Nafsul",
 * bersebelahan dengan Transaksi, lalu diberi akses ke semua authority.
 *
 * Polanya mengikuti add_nafsul_transaksi_menu: idempoten (aman dijalankan ulang
 * atau setelah menunya dibuat manual lewat Master Menu), dan baris authority_menu
 * WAJIB ikut dibuat — tanpanya menunya ada di database tapi tidak pernah muncul
 * di sidebar siapa pun, karena sidebar dibangun dari menu milik authority
 * pengguna, bukan dari seluruh isi tabel menus.
 */
return new class extends Migration
{
    private const URL = '/nafsul/jasa-anggota';

    private const TITLE = 'Nafsul';

    public function up(): void
    {
        if (DB::table('menus')->where('url', self::URL)->exists()) {
            return;
        }

        $titleId = DB::table('title_menuses')->where('title', self::TITLE)->value('id');

        if (! $titleId) {
            $urutan = (int) DB::table('title_menuses')->max('sort_order') + 1;

            $titleId = DB::table('title_menuses')->insertGetId([
                'title' => self::TITLE,
                'sort_order' => $urutan,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Menyusul menu Nafsul yang sudah ada, bukan menyalipnya.
        $urutanMenu = (int) DB::table('menus')
            ->where('title_menu_id', $titleId)
            ->whereNull('parent_id')
            ->max('sort_order') + 1;

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $titleId,
            'parent_id' => null,
            'name' => 'Jasa Anggota',
            'url' => self::URL,
            'icon' => 'hand-coins',
            'sort_order' => $urutanMenu,
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
        $menu = DB::table('menus')->where('url', self::URL)->first();

        if ($menu) {
            DB::table('authority_menu')->where('menu_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
        }

        // Title "Nafsul" TIDAK ikut dihapus: menu Transaksi masih memakainya.
    }
};
