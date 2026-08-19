<?php

namespace Database\Seeders;

use App\Models\Education;
use Illuminate\Database\Seeder;

class PendidikanSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'SD',
            'SMP',
            'SLTA',
            'SMU',
            'DIPLOMA',
            'SARJANA',
            'S1',
            'S2',
            'S3',
            'Magister',
            'Doktor',
            'Mahasiswa',
            'Pelajar',
            'Lain-Lain',
        ];

        foreach ($data as $name) {
            Education::firstOrCreate(['name' => $name]);
        }
    }
}
