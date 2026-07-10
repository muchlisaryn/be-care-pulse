<?php

namespace Database\Seeders;

use App\Models\Printer;
use Illuminate\Database\Seeder;

/**
 * Master printer default: Epson TM-T82X (struk / ESC/POS) via share Windows
 * bernama "care-pulse".
 *
 * Memakai updateOrCreate, bukan firstOrCreate: baris yang sudah terlanjur diubah
 * lewat UI (mis. jadi connection_type=network + ip_address=localhost, yang tidak
 * pernah bisa mencetak karena port 9100 milik printer jaringan, bukan Apache)
 * ikut dikembalikan ke nilai benar tiap seeder dijalankan.
 *
 * device_path = tujuan WindowsPrintConnector di print server:
 *   - "care-pulse"                         → share di komputer yang sama
 *   - "smb://192.168.1.10/care-pulse"      → share di komputer lain (pakai IP/hostname)
 *   - "smb://user:pass@192.168.1.10/care-pulse" → bila share butuh login
 *   - "COM3" / "LPT1"                      → port lokal langsung
 * Untuk printer yang benar-benar punya kartu jaringan sendiri, barulah pakai
 * connection_type=network + ip_address=<IP printer> + port=9100.
 *
 * Jalankan: php artisan db:seed --class=PrinterSeeder
 */
class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        Printer::updateOrCreate(
            ['name' => 'Epson TM-T82X'],
            [
                'document_type' => 'struk',
                'printer_language' => 'escpos',
                // TM-T82X tersambung USB, dicetak lewat share Windows — bukan network.
                'connection_type' => 'usb',
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
