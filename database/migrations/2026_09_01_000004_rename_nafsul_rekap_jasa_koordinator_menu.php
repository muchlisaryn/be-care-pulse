<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ganti nama menu "Rekap Jasa" → "Rekap Jasa Koordinator".
 *
 * HANYA namanya. `url` sengaja dibiarkan `/nafsul/rekap-jasa`: alamatnya masih
 * menggambarkan isinya, sedangkan tiap kali url menu berubah setiap pengguna
 * harus logout–login dulu (sidebar dibangun dari menu yang di-cache saat login).
 * Menukar alamat demi selisih satu kata tidak sepadan dengan itu.
 *
 * Seperti migrasi rename sebelumnya: baris menunya di-`update`, bukan dihapus
 * lalu dibuat ulang — id menunya harus tetap agar baris `authority_menu` tidak
 * ikut tercabut.
 */
return new class extends Migration
{
    private const URL = '/nafsul/rekap-jasa';

    public function up(): void
    {
        DB::table('menus')->where('url', self::URL)->update([
            'name' => 'Rekap Jasa Koordinator',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', self::URL)->update([
            'name' => 'Rekap Jasa',
            'updated_at' => now(),
        ]);
    }
};
