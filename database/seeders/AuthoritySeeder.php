<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\Menu;
use Illuminate\Database\Seeder;

/**
 * Otoritas "Administrator" — pemegang seluruh menu.
 *
 * Dipertahankan (bersama AdminUserSeeder) meski seeder lain sudah dibersihkan:
 * tanpa keduanya database baru tidak punya satu pun akun yang bisa login, dan
 * menu Otoritas sendiri hanya bisa dibuka setelah login. Otoritas selain
 * Administrator dibuat lewat menu Otoritas, bukan di sini.
 *
 * WAJIB dijalankan SETELAH SELURUH seeder menu (TitleMenu, Menu, NafsulMenu, dan
 * menu tambahan pasca-rilis): yang di-attach adalah isi tabel `menus` pada saat
 * seeder ini jalan, jadi menu yang lahir belakangan tidak akan ikut terbagi.
 *
 * Jalankan sendiri: php artisan db:seed --class=AuthoritySeeder
 */
class AuthoritySeeder extends Seeder
{
    public function run(): void
    {
        // syncWithoutDetaching, BUKAN attach/sync: attach menabrak primary key saat
        // seeder diulang, sedangkan sync akan MENCABUT hak yang diatur admin lewat
        // UI. Ini hanya menambahkan menu yang belum terhubung — termasuk menu baru
        // yang lahir dari seeder pasca-rilis.
        $this->authority('Administrator', 'Akses penuh ke seluruh fitur sistem')
            ->menus()
            ->syncWithoutDetaching(Menu::pluck('id')->all());
    }

    /**
     * Ambil authority bernama `$name`, buat bila belum ada.
     *
     * Wajib idempotent: `authorities.name` punya index UNIK, jadi create() polos
     * membuat `db:seed` ulang gagal dengan 1062 Duplicate entry. withTrashed()
     * dipakai karena index unik itu tidak peduli soft delete — authority yang
     * sudah dihapus admin tetap menempati namanya.
     */
    private function authority(string $name, string $description): Authority
    {
        return Authority::withTrashed()->firstOrCreate(
            ['name' => $name],
            ['description' => $description]
        );
    }
}
