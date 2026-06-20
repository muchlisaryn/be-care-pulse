<?php

namespace Database\Seeders;

use App\Models\Condition;
use Illuminate\Database\Seeder;

class ConditionSeeder extends Seeder
{
    public function run(): void
    {
        $conditions = ['Baik', 'Cukup Baik', 'Rusak Ringan', 'Rusak Berat', 'Dalam Perbaikan'];

        foreach ($conditions as $name) {
            Condition::firstOrCreate(['name' => $name]);
        }
    }
}
