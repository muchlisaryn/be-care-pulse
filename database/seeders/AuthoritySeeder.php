<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\Menu;
use Illuminate\Database\Seeder;

class AuthoritySeeder extends Seeder
{
    public function run(): void
    {
        // Administrator — akses semua menu (parent + children)
        $administrator = $this->authority('Administrator', 'Akses penuh ke seluruh fitur sistem');
        // syncWithoutDetaching, BUKAN attach/sync: attach menabrak primary key saat
        // seeder diulang, sedangkan sync akan MENCABUT hak yang diatur admin lewat
        // UI. Ini hanya menambahkan menu yang belum terhubung — termasuk menu baru
        // yang lahir dari seeder pasca-rilis.
        $administrator->menus()->syncWithoutDetaching(Menu::pluck('id')->all());

        // Operator — hanya Dashboard
        $operator = $this->authority('Operator', 'Akses terbatas pada fitur operasional');
        $operator->menus()->syncWithoutDetaching(
            Menu::where('name', 'Dashboard')->pluck('id')->all()
        );
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
