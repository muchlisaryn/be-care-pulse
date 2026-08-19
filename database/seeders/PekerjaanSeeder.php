<?php

namespace Database\Seeders;

use App\Models\Occupation;
use Illuminate\Database\Seeder;

class PekerjaanSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'PNS',
            'BUMN',
            'Karyawan',
            'Wiraswasta',
            'Pedagang',
            'Buruh',
            'Buruh Harian',
            'Guru',
            'Dosen',
            'Dokter',
            'Bidan',
            'Pengacara',
            'Arsitek',
            'Pilot',
            'Sopir',
            'Wartawan',
            'IT',
            'Software Engginer',
            'Lembaga tinggi',
            'IRT',
            'ART',
            'PRT',
            'Mahasiswa',
            'Mahasiswi',
            'Pelajar',
            'Pensiuanan',
            'Lain-Lain',
        ];

        foreach ($data as $name) {
            Occupation::firstOrCreate(['name' => $name]);
        }
    }
}
