<?php

namespace Database\Seeders;

use App\Models\Condition;
use Illuminate\Database\Seeder;

class ConditionSeeder extends Seeder
{
    public function run(): void
    {
        // Kondisi pengembalian (tombol B/KB/H/R) + kondisi lama (tetap dipertahankan
        // agar referensi condition_out yang sudah ada tidak rusak).
        $conditions = [
            'Baik', 'Kurang Baik', 'Hilang', 'Rusak',
            'Cukup Baik', 'Rusak Ringan', 'Rusak Berat', 'Dalam Perbaikan',
        ];

        foreach ($conditions as $name) {
            Condition::firstOrCreate(['name' => $name]);
        }
    }
}
