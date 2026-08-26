<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Master kota — dipakai form anggota sebagai kota lahir (`kode_kota_lahir`).
 *
 * Kodenya sengaja berurut sederhana (`KT01`, `KT02`, …), bukan kode BPS: kode
 * BPS tidak diverifikasi di sini, dan kode yang salah lebih menyesatkan
 * daripada kode yang jelas-jelas internal. Ganti lewat halaman Master Kota
 * (atau impor Excel) bila organisasi sudah punya daftar kode sendiri.
 *
 * Daftarnya kota-kota besar Indonesia sebagai titik awal — bukan daftar
 * lengkap.
 */
class KotaSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['KT01', 'Jakarta Pusat'],
            ['KT02', 'Jakarta Utara'],
            ['KT03', 'Jakarta Barat'],
            ['KT04', 'Jakarta Selatan'],
            ['KT05', 'Jakarta Timur'],
            ['KT06', 'Bekasi'],
            ['KT07', 'Depok'],
            ['KT08', 'Bogor'],
            ['KT09', 'Tangerang'],
            ['KT10', 'Tangerang Selatan'],
            ['KT11', 'Bandung'],
            ['KT12', 'Cirebon'],
            ['KT13', 'Sukabumi'],
            ['KT14', 'Tasikmalaya'],
            ['KT15', 'Semarang'],
            ['KT16', 'Surakarta'],
            ['KT17', 'Magelang'],
            ['KT18', 'Pekalongan'],
            ['KT19', 'Tegal'],
            ['KT20', 'Yogyakarta'],
            ['KT21', 'Surabaya'],
            ['KT22', 'Malang'],
            ['KT23', 'Kediri'],
            ['KT24', 'Madiun'],
            ['KT25', 'Medan'],
            ['KT26', 'Padang'],
            ['KT27', 'Palembang'],
            ['KT28', 'Pekanbaru'],
            ['KT29', 'Bandar Lampung'],
            ['KT30', 'Denpasar'],
            ['KT31', 'Makassar'],
            ['KT32', 'Banjarmasin'],
            ['KT33', 'Balikpapan'],
            ['KT34', 'Pontianak'],
            ['KT35', 'Manado'],
        ];

        foreach ($data as [$code, $name]) {
            City::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
