<?php

namespace Database\Seeders;

use App\Models\SterilizerMachine;
use Illuminate\Database\Seeder;

/**
 * Master mesin sterilisator (autoclave) — tahap Sterilization.
 *
 * `code` (STL-NNN) DITULIS EKSPLISIT di sini, tidak mengandalkan auto-generate.
 * Model memakai trait HasAutoCode yang mengisi `code` lewat event `creating`,
 * sedangkan `DatabaseSeeder` memakai `WithoutModelEvents` — event dimatikan, jadi
 * insert tanpa code eksplisit gagal ("Field 'code' doesn't have a default value").
 * Kode berikutnya yang dibuat lewat UI otomatis lanjut dari nomor terbesar di sini.
 *
 * `temperature` & `duration_minutes` adalah nilai STANDAR yang dipakai operator
 * sebagai acuan saat menjalankan & memvalidasi batch. Masa simpan steril BUKAN
 * urusan mesin — tgl kedaluwarsa ditentukan jenis kemasan (master PackagingType).
 *
 * Memakai updateOrCreate berkunci `code` supaya idempotent.
 *
 * Jalankan: php artisan db:seed --class=SterilizerMachineSeeder
 */
class SterilizerMachineSeeder extends Seeder
{
    public function run(): void
    {
        $machines = [
            [
                'code' => 'STL-001',
                'name' => 'Autoclave Pre-Vacuum 1',
                'location' => 'Ruang Sterilisasi',
                // Siklus prevakum: holding 134°C, total siklus ±30 menit.
                'temperature' => 134,
                'duration_minutes' => 30,
                'note' => 'Instrumen logam & linen berbungkus.',
            ],
            [
                'code' => 'STL-002',
                'name' => 'Autoclave Gravitasi 1',
                'location' => 'Ruang Sterilisasi',
                // Siklus gravitasi lebih lama pada suhu lebih rendah.
                'temperature' => 121,
                'duration_minutes' => 45,
                'note' => 'Instrumen tidak berlumen & cairan.',
            ],
            [
                'code' => 'STL-003',
                'name' => 'Sterilisator Suhu Rendah H2O2',
                'location' => 'Ruang Sterilisasi',
                // Plasma hidrogen peroksida untuk alat yang tidak tahan panas.
                'temperature' => 55,
                'duration_minutes' => 55,
                'note' => 'Alat sensitif panas: endoskop, kamera, kabel.',
            ],
        ];

        foreach ($machines as $machine) {
            SterilizerMachine::updateOrCreate(
                ['code' => $machine['code']],
                [
                    ...$machine,
                    'status' => SterilizerMachine::STATUS_AKTIF,
                ],
            );
        }
    }
}
