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
        $administrator = Authority::create([
            'name' => 'Administrator',
            'description' => 'Akses penuh ke seluruh fitur sistem',
        ]);
        $administrator->menus()->attach(Menu::pluck('id')->toArray());

        // Operator — hanya Dashboard
        $operator = Authority::create([
            'name' => 'Operator',
            'description' => 'Akses terbatas pada fitur operasional',
        ]);
        $operator->menus()->attach(
            Menu::where('name', 'Dashboard')->pluck('id')->toArray()
        );
    }
}
