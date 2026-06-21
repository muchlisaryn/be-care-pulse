<?php

namespace Database\Seeders;

use App\Models\CategoriClinicalPathway;
use Illuminate\Database\Seeder;

class CategoriClinicalPathwaySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['urutan' => 1, 'label' => 'Anamnesis'],
            ['urutan' => 2, 'label' => 'Pemeriksaan Fisik'],
            ['urutan' => 3, 'label' => 'Pemeriksaan Penunjang'],
            ['urutan' => 4, 'label' => 'Diagnosis'],
            ['urutan' => 5, 'label' => 'Tata Laksana'],
        ];

        // updateOrCreate berdasarkan `urutan` (unik) agar idempoten — aman dijalankan ulang.
        foreach ($items as $item) {
            CategoriClinicalPathway::updateOrCreate(
                ['urutan' => $item['urutan']],
                ['label' => $item['label']]
            );
        }
    }
}
