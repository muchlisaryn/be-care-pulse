<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ganti nama & url menu "Jasa Ketua Kelompok" → "Rekap Jasa".
 *
 * Sama seperti migrasi rename sebelumnya: baris menunya di-`update`, bukan
 * dihapus lalu dibuat ulang — id menunya harus tetap agar baris `authority_menu`
 * (hak akses per otoritas yang sudah diatur) tidak ikut tercabut.
 *
 * Url lama ikut dicari sebagai cadangan untuk database yang belum sempat
 * menjalankan migrasi rename pertama, sehingga keduanya bermuara ke url yang
 * sama tanpa menyisakan menu ganda.
 */
return new class extends Migration
{
    private const URL_LAMA = [
        '/nafsul/jasa-ketua-kelompok',
        '/nafsul/jasa-anggota',
    ];

    private const URL_BARU = '/nafsul/rekap-jasa';

    public function up(): void
    {
        DB::table('menus')->whereIn('url', self::URL_LAMA)->update([
            'name' => 'Rekap Jasa',
            'url' => self::URL_BARU,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', self::URL_BARU)->update([
            'name' => 'Jasa Ketua Kelompok',
            'url' => self::URL_LAMA[0],
            'updated_at' => now(),
        ]);
    }
};
