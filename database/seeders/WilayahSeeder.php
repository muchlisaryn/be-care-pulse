<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Master wilayah Nafsul.
 *
 * Isinya data awal, bukan data resmi organisasi — silakan ganti lewat halaman
 * Master Wilayah setelah daftar wilayah yang sebenarnya tersedia. Kodenya yang
 * dirujuk anggota (`kode_wilayah`), jadi mengubah kode setelah ada anggota
 * terdaftar akan memutus kaitannya.
 *
 * `firstOrCreate` per kode: aman dijalankan berulang dan tidak menimpa nama
 * yang sudah disunting petugas.
 */
class WilayahSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['01', 'Jakarta Pusat'],
            ['02', 'Jakarta Utara'],
            ['03', 'Jakarta Barat'],
            ['04', 'Jakarta Selatan'],
            ['05', 'Jakarta Timur'],
            ['06', 'Bekasi'],
            ['07', 'Depok'],
            ['08', 'Bogor'],
            ['09', 'Tangerang'],
            ['10', 'Tangerang Selatan'],
        ];

        foreach ($data as [$code, $name]) {
            Region::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
