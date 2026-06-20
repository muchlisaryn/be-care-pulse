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

        User::create([
            'name' => 'Administrator',
            'username' => 'administrator',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('Admin@12345'),
            'no_telephone' => '081234567890',
            'authority_id' => $adminAuthority?->id,
            'email_verified_at' => now(),
        ]);
    }
}
