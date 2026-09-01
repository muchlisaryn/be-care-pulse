<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ganti nama & url menu "Jasa Anggota" → "Jasa Ketua Kelompok".
 *
 * Migrasi TERSENDIRI, bukan menyunting 2026_09_01_000001: migrasi itu sudah
 * berjalan di database, jadi mengubah isinya tidak akan berpengaruh apa-apa —
 * yang tercatat di tabel `migrations` cuma namanya.
 *
 * Baris `authority_menu` sengaja tidak disentuh: yang berubah hanya label dan
 * alamatnya, sedangkan id menunya tetap. Menghapus lalu membuat ulang menunya
 * akan mencabut hak akses yang sudah diatur per otoritas.
 */
return new class extends Migration
{
    private const URL_LAMA = '/nafsul/jasa-anggota';

    private const URL_BARU = '/nafsul/jasa-ketua-kelompok';

    public function up(): void
    {
        DB::table('menus')->where('url', self::URL_LAMA)->update([
            'name' => 'Jasa Ketua Kelompok',
            'url' => self::URL_BARU,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', self::URL_BARU)->update([
            'name' => 'Jasa Anggota',
            'url' => self::URL_LAMA,
            'updated_at' => now(),
        ]);
    }
};
