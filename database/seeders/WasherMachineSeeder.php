<?php

namespace Database\Seeders;

use App\Models\WasherMachine;
use Illuminate\Database\Seeder;

/**
 * Master mesin pencuci (washer disinfector) — tahap Cleaning & Disinfection.
 *
 * `temperature` & `duration_minutes` adalah nilai STANDAR mesin yang diperlakukan
 * sebagai batas MINIMUM: saat petugas menyimpan catatan pencucian, suhu/durasi di
 * bawah angka ini ditandai gagal (lihat WasherMachine::evaluate()). Nilainya juga
 * dipakai untuk auto-isi form, jadi isi sesuai program cuci yang benar-benar
 * dipakai di unit CSSD masing-masing.
 *
 * Memakai updateOrCreate berdasarkan `name` supaya idempotent — dijalankan berkali
 * -kali tidak menggandakan data, dan nilai standar yang terlanjur meleset ikut
 * dikembalikan.
 *
 * Jalankan: php artisan db:seed --class=WasherMachineSeeder
 */
class WasherMachineSeeder extends Seeder
{
    public function run(): void
    {
        $machines = [
            [
                'name' => 'Washer Disinfector 1',
                'location' => 'Ruang Dekontaminasi',
                // Disinfeksi termal: 90°C, siklus penuh ±30 menit.
                'temperature' => 90,
                'duration_minutes' => 30,
                'note' => 'Program disinfeksi termal untuk instrumen bedah umum.',
            ],
            [
                'name' => 'Washer Disinfector 2',
                'location' => 'Ruang Dekontaminasi',
                'temperature' => 90,
                'duration_minutes' => 30,
                'note' => 'Mesin cadangan / beban puncak.',
            ],
            [
                'name' => 'Ultrasonic Cleaner',
                'location' => 'Ruang Dekontaminasi',
                // Pembersihan ultrasonik memang bersuhu rendah — jangan disamakan
                // dengan washer disinfector.
                'temperature' => 40,
                'duration_minutes' => 15,
                'note' => 'Pra-pembersihan instrumen berlumen & bersendi.',
            ],
        ];

        foreach ($machines as $machine) {
            WasherMachine::updateOrCreate(
                ['name' => $machine['name']],
                [
                    ...$machine,
                    'status' => WasherMachine::STATUS_AKTIF,
                ],
            );
        }
    }
}
