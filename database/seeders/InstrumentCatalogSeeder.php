<?php

namespace Database\Seeders;

use App\Models\Condition;
use App\Models\Instrument;
use App\Models\InstrumentCatalog;
use Illuminate\Database\Seeder;

class InstrumentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Daftar instrumen (ALAT/INSTRUMEN) sesuai "Paket Standar Alat/Instrumen CSSD".
        // Kode dibuat manual & bermakna karena dipakai sebagai prefix kode unit fisik (mis. GNT-001).
        $instruments = [
            'GNT' => 'Gunting',
            'KSL' => 'Kasa Lipat 6x6',
            'KLL' => 'Klem Lurus',
            'KMK' => 'Kom Kecil',
            'PSA' => 'Pinset Anatomis',
            'PSC' => 'Pinset Chirurgis',
            'NLF' => 'Nalfuder',
            'DKB' => 'Duk Belah',
            'SRT' => 'Sarung Tangan',
            'GNB' => 'Gunting Benang',
            'KLB' => 'Klem Bengkok',
            'KOM' => 'Kom',
            'PAK' => 'Pinset Anatomis Kecil',
            'PCK' => 'Pinset Chirurgis Kecil',
            'GNK' => 'Gunting Kecil',
            'DBO' => 'Duk Bolong',
            'DKS' => 'Duk Spinal / Duk Biasa',
            'GNE' => 'Gunting Epis',
            'GTP' => 'Gunting Tali Pusar',
            'KLM' => 'Klem',
            'KHR' => '1/2 Koher',
        ];

        $instrumentIds = [];
        foreach ($instruments as $code => $name) {
            $instrumentIds[$code] = Instrument::firstOrCreate(['code' => $code], ['name' => $name])->id;
        }

        $baik = Condition::where('name', 'Baik')->value('id');

        // Paket standar CSSD: setiap paket berisi beberapa rincian instrumen + jumlahnya.
        // Format item: [kode_instrumen, jumlah].
        $packages = [
            [
                'code' => 'GV-SET',
                'name' => 'GV SET',
                'items' => [['GNT', 1], ['KSL', 5], ['KLL', 1], ['KMK', 1], ['PSA', 1], ['PSC', 1]],
            ],
            [
                'code' => 'HECTING-SET',
                'name' => 'HECTING SET',
                'items' => [['GNT', 1], ['KLL', 1], ['KMK', 1], ['NLF', 1], ['PSC', 1]],
            ],
            [
                'code' => 'GV-HD-SET',
                'name' => 'GV HD SET',
                'items' => [['DKB', 1], ['KSL', 3], ['KLL', 1], ['KMK', 2], ['SRT', 1]],
            ],
            [
                'code' => 'CDL-SET',
                'name' => 'CDL SET',
                'items' => [['DKB', 1], ['GNB', 1], ['KLB', 1], ['KLL', 1], ['KOM', 3], ['NLF', 1], ['PSC', 1]],
            ],
            [
                'code' => 'VC-SET',
                'name' => 'VC SET',
                'items' => [['PAK', 1], ['PCK', 1], ['NLF', 1], ['GNK', 1], ['DBO', 1], ['KSL', 5], ['KLB', 2], ['KLL', 1]],
            ],
            [
                'code' => 'SET-BAYI',
                'name' => 'SET BAYI',
                'items' => [['DKS', 3], ['GNT', 1], ['KSL', 5], ['SRT', 2]],
            ],
            [
                'code' => 'SET-PARTUS',
                'name' => 'SET PARTUS',
                'items' => [['GNE', 1], ['GTP', 1], ['KLM', 2], ['KHR', 1], ['KMK', 1]],
            ],
        ];

        foreach ($packages as $pkg) {
            $catalog = InstrumentCatalog::firstOrCreate(
                ['code' => $pkg['code']],
                ['name' => $pkg['name'], 'type' => 'paket', 'description' => 'Paket standar alat/instrumen CSSD.']
            );

            foreach ($pkg['items'] as [$instrCode, $qty]) {
                $catalog->items()->firstOrCreate(
                    ['instrument_id' => $instrumentIds[$instrCode]],
                    ['quantity' => $qty, 'standard_condition_id' => $baik]
                );
            }
        }
    }
}
