<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            TitleMenuSeeder::class,
            MenuSeeder::class,
            AuthoritySeeder::class,
            AdminUserSeeder::class,
            RoomSeeder::class,
            ConditionSeeder::class,
            InstrumentCatalogSeeder::class,
            InstrumentStockSeeder::class,
        ]);
    }
}
