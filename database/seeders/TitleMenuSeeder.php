<?php

namespace Database\Seeders;

use App\Models\TitleMenus;
use Illuminate\Database\Seeder;

class TitleMenuSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['title' => 'Dashboard', 'sort_order' => 1],
            ['title' => 'Master Data', 'sort_order' => 2],
            ['title' => 'Cssd', 'sort_order' => 3],
        ];

        foreach ($items as $item) {
            TitleMenus::create($item);
        }
    }
}
