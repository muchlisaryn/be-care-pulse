<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Nomor pegawai admin. Form login hanya menerima angka, jadi username admin
     * ikut memakai format nomor pegawai — bukan lagi 'administrator'.
     */
    private const ADMIN_NOPEG = '000001';

    public function run(): void
    {
        $adminAuthority = Authority::where('name', 'Administrator')->first();

        // Database yang sudah ter-seed sebelum perubahan format masih memakai
        // 'administrator'. Pindahkan dulu ke nomor pegawai — kalau tidak,
        // firstOrCreate di bawah justru membuat admin KEDUA.
        User::withTrashed()
            ->where('username', 'administrator')
            ->update(['username' => self::ADMIN_NOPEG]);

        // Idempotent: `users.username` unik, jadi create() polos membuat `db:seed`
        // ulang gagal dengan 1062 Duplicate entry. withTrashed() dipakai karena
        // index unik tidak peduli soft delete.
        //
        // Password & profil SENGAJA hanya diisi saat user dibuat — menjalankan
        // seeder ulang tidak boleh mengembalikan password admin ke nilai default.
        User::withTrashed()->firstOrCreate(
            ['username' => self::ADMIN_NOPEG],
            [
                'name' => 'Administrator',
                'email' => 'admin@gmail.com',
                'password' => Hash::make('Admin@12345'),
                'no_telephone' => '081234567890',
                'authority_id' => $adminAuthority?->id,
                'email_verified_at' => now(),
            ]
        );
    }
}
