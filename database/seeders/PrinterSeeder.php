<?php

namespace Database\Seeders;

use App\Models\Printer;
use Illuminate\Database\Seeder;

/**
 * Master printer default: Epson TM-T82X (struk / ESC/POS) via share Windows
 * bernama "care-pulse". Idempotent — aman dijalankan berulang.
 *
 * Jalankan: php artisan db:seed --class=PrinterSeeder
 */
class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        Printer::firstOrCreate(
            ['name' => 'Epson TM-T82X'],
            [
                'document_type' => 'struk',
                'printer_language' => 'escpos',
                'connection_type' => 'usb',
                // Nama share printer di Windows (dipakai print server ESC/POS).
                'device_path' => 'care-pulse',
                'ip_address' => null,
                'port' => null,
                // TM-T82X = printer struk 80mm.
                'paper_size' => '80mm',
                'char_per_line' => null,
                'auto_cut' => true,
                'label_width_mm' => null,
                'label_height_mm' => null,
                'label_gap_mm' => null,
                'code_page' => 'CP437',
                'is_active' => true,
            ],
        );
    }
}
