<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminAuthority = Authority::where('name', 'Administrator')->first();

        // Idempotent: `users.username` unik, jadi create() polos membuat `db:seed`
        // ulang gagal dengan 1062 Duplicate entry. withTrashed() dipakai karena
        // index unik tidak peduli soft delete.
        //
        // Password & profil SENGAJA hanya diisi saat user dibuat — menjalankan
        // seeder ulang tidak boleh mengembalikan password admin ke nilai default.
        User::withTrashed()->firstOrCreate(
            ['username' => 'administrator'],
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
