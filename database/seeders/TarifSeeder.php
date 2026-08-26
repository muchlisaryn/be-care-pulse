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
 * Kolom `fee_type` memisahkan sifat pembayarannya:
 *
 * | `fee_type`  | Arti                                                       |
 * | ----------- | ---------------------------------------------------------- |
 * | `recurring` | berulang tiap periode — `price` dikalikan jumlah bulan      |
 * | `one_time`  | sekali bayar — nominalnya berdiri sendiri                   |
 *
 * Tarif berkategori `iuran` yang dipakai halaman Transaksi: `price`-nya jadi
 * nominal bawaan tiap periode saat petugas memasukkan jumlah bulan.
 *
 * Nominal & sifat tarif di bawah cuma data awal — sesuaikan lewat halaman
 * Master Tarif.
 */
class TarifSeeder extends Seeder
{
    public function run(): void
    {
        $iuran = [
            ['IUR01', 'Iuran Bulanan', 50000, Rate::FEE_TYPE_RECURRING, 'Iuran rutin anggota per bulan'],
            ['IUR02', 'Iuran Sosial', 20000, Rate::FEE_TYPE_RECURRING, 'Dana sosial dan santunan'],
            ['IUR03', 'Iuran Pembangunan', 25000, Rate::FEE_TYPE_ONE_TIME, 'Dana pembangunan dan sarana'],
        ];

        foreach ($iuran as [$code, $name, $price, $feeType, $note]) {
            $this->tarif($code, [
                'category' => 'iuran',
                'name' => $name,
                'price' => $price,
                'fee_type' => $feeType,
                'note' => $note,
            ]);
        }

        // Kas keluar selalu sekali bayar: santunan & operasional dicatat per
        // kejadian, tidak pernah dikalikan jumlah periode.
        $kasKeluar = [
            ['KK01', 'Santunan Duka', 1000000, 'Santunan untuk keluarga anggota yang meninggal'],
            ['KK02', 'Santunan Sakit', 500000, 'Santunan anggota yang dirawat inap'],
            ['KK03', 'Operasional', 0, 'Biaya operasional organisasi'],
            ['KK04', 'Jasa Ketua Kelompok', 0, 'Komisi penagihan iuran oleh ketua kelompok'],
        ];

        foreach ($kasKeluar as [$code, $name, $price, $note]) {
            $this->tarif($code, [
                'category' => 'kas_keluar',
                'name' => $name,
                'price' => $price,
                'fee_type' => Rate::FEE_TYPE_ONE_TIME,
                'note' => $note,
            ]);
        }
    }

    /**
     * Buat tarif bila belum ada; bila sudah ada, isi `fee_type`-nya SAJA dan
     * hanya kalau masih kosong.
     *
     * `fee_type` menyusul belakangan lewat migrasi (kolomnya nullable tanpa
     * default), jadi seluruh baris yang lahir sebelum itu — termasuk tarif yang
     * dibuat petugas sendiri lewat Master Tarif — tertinggal NULL dan tidak akan
     * pernah terisi oleh firstOrCreate biasa. Pengisian dibatasi pada baris yang
     * masih NULL supaya klasifikasi yang sudah diubah petugas tidak ditimpa tiap
     * `db:seed` diulang; harga & nama juga tetap tidak disentuh.
     */
    private function tarif(string $code, array $attributes): void
    {
        $rate = Rate::withTrashed()->firstOrCreate(['code' => $code], $attributes);

        if ($rate->wasRecentlyCreated || $rate->fee_type !== null) {
            return;
        }

        $rate->fee_type = $attributes['fee_type'];
        $rate->save();
    }
}
