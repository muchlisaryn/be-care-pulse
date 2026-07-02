<?php

namespace Database\Seeders;

use App\Models\CategoriClinicalPathway;
use Illuminate\Database\Seeder;

class CategoriClinicalPathwaySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['sort_order' => 1, 'label' => 'Anamnesis'],
            ['sort_order' => 2, 'label' => 'Pemeriksaan Fisik'],
            ['sort_order' => 3, 'label' => 'Pemeriksaan Penunjang'],
            ['sort_order' => 4, 'label' => 'Diagnosis'],
            ['sort_order' => 5, 'label' => 'Tata Laksana'],
        ];

        // updateOrCreate berdasarkan `sort_order` (unik) agar idempoten — aman dijalankan ulang.
        foreach ($items as $item) {
            CategoriClinicalPathway::updateOrCreate(
                ['sort_order' => $item['sort_order']],
                ['label' => $item['label']]
            );
        }
    }
}
