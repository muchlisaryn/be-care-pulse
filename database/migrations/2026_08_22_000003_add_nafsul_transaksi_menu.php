<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Title menu "Nafsul" dengan satu menu "Transaksi" (/nafsul/transaksi) di
 * dalamnya, lalu diberi akses ke semua authority.
 *
 * Master Nafsul tetap berada di title "Master Data" → grup "Master Nafsul".
 * Yang dipisahkan ke title sendiri hanya transaksinya, karena itu pekerjaan
 * harian dan bukan pengaturan data acuan.
 */
return new class extends Migration
{
    private const URL = '/nafsul/transaksi';

    private const TITLE = 'Nafsul';

    public function up(): void
    {
        // Migrasi ini pernah dijalankan / menunya dibuat manual.
        if (DB::table('menus')->where('url', self::URL)->exists()) {
            return;
        }

        $titleId = DB::table('title_menuses')->where('title', self::TITLE)->value('id');

        if (! $titleId) {
            // Ditaruh setelah title yang sudah ada supaya tidak menyalip menu
            // yang urutannya sudah disepakati.
            $urutan = (int) DB::table('title_menuses')->max('sort_order') + 1;

            $titleId = DB::table('title_menuses')->insertGetId([
                'title' => self::TITLE,
                'sort_order' => $urutan,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menuId = DB::table('menus')->insertGetId([
            'title_menu_id' => $titleId,
            'parent_id' => null,
            'name' => 'Transaksi',
            'url' => self::URL,
            'icon' => 'wallet',
            'sort_order' => 1,
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

        // Title hanya dihapus kalau memang sudah tidak dipakai menu lain —
        // bisa saja sudah ada menu Nafsul lain yang ditambahkan setelah ini.
        $titleId = DB::table('title_menuses')->where('title', self::TITLE)->value('id');

        if ($titleId && ! DB::table('menus')->where('title_menu_id', $titleId)->exists()) {
            DB::table('title_menuses')->where('id', $titleId)->delete();
        }
    }
};
