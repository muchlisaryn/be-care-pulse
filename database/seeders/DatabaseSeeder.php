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
            // Menu tambahan pasca-rilis (idempotent) — aman untuk DB lama & baru.
            RakMenuSeeder::class,
            PengaturanMenuSeeder::class,
            PrinterSeeder::class,
            AdminUserSeeder::class,
            RoomSeeder::class,
            ConditionSeeder::class,
            InstrumentCatalogSeeder::class,
            InstrumentStockSeeder::class,
            // Master mesin pipeline CSSD (cleaning & sterilisasi).
            WasherMachineSeeder::class,
            SterilizerMachineSeeder::class,
            CategoriClinicalPathwaySeeder::class,
        ]);
    }
}
