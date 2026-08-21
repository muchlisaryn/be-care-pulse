<?php

namespace Database\Seeders;

use App\Models\Rate;
use Illuminate\Database\Seeder;

/**
 * Master tarif Nafsul.
 *
 * Satu tabel menampung dua hal yang dipisahkan kolom `category`, dan nilainya
 * **harus persis** seperti di bawah — halaman frontend menyaring dengan nilai
 * itu:
 *
 * | `category`  | Halaman                          |
 * | ----------- | -------------------------------- |
 * | `iuran`     | /nafsul/master/tarif/iuran       |
 * | `kas_keluar`| /nafsul/master/tarif/kas-keluar  |
 *
 * Tarif berkategori `iuran` yang dipakai halaman Transaksi: `price`-nya jadi
 * nominal bawaan tiap periode saat petugas memasukkan jumlah bulan.
 *
 * Nominalnya data awal — sesuaikan lewat halaman Master Tarif.
 */
class TarifSeeder extends Seeder
{
    public function run(): void
    {
        $iuran = [
            ['IUR01', 'Iuran Bulanan', 50000, 'Iuran rutin anggota per bulan'],
            ['IUR02', 'Iuran Sosial', 20000, 'Dana sosial dan santunan'],
            ['IUR03', 'Iuran Pembangunan', 25000, 'Dana pembangunan dan sarana'],
        ];

        foreach ($iuran as [$code, $name, $price, $note]) {
            Rate::firstOrCreate(['code' => $code], [
                'category' => 'iuran',
                'name' => $name,
                'price' => $price,
                'note' => $note,
            ]);
        }

        $kasKeluar = [
            ['KK01', 'Santunan Duka', 1000000, 'Santunan untuk keluarga anggota yang meninggal'],
            ['KK02', 'Santunan Sakit', 500000, 'Santunan anggota yang dirawat inap'],
            ['KK03', 'Operasional', 0, 'Biaya operasional organisasi'],
            ['KK04', 'Jasa Ketua Kelompok', 0, 'Komisi penagihan iuran oleh ketua kelompok'],
        ];

        foreach ($kasKeluar as [$code, $name, $price, $note]) {
            Rate::firstOrCreate(['code' => $code], [
                'category' => 'kas_keluar',
                'name' => $name,
                'price' => $price,
                'note' => $note,
            ]);
        }
    }
}
