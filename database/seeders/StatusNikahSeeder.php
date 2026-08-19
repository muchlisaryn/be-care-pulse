<?php

namespace Database\Seeders;

use App\Models\MaritalStatus;
use Illuminate\Database\Seeder;

class StatusNikahSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Lajang',
            'Menikah',
            'Janda',
            'Duda',
        ];

        foreach ($data as $name) {
            MaritalStatus::firstOrCreate(['name' => $name]);
        }
    }
}
