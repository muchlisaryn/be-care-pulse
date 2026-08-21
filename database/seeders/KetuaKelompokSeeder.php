<?php

namespace Database\Seeders;

use App\Models\GroupLeader;
use Illuminate\Database\Seeder;

/**
 * Master ketua kelompok.
 *
 * **Baris "Pribadi" wajib ada.** Itu bukan orang, melainkan penampung anggota
 * perorangan: `Member::filterTipe()` memisahkan anggota pribadi dari anggota
 * kelompok dengan mencocokkan nama ketuanya ke `GroupLeader::NAMA_PRIBADI`.
 * Tanpa baris ini, statistik "pribadi" selalu 0 dan anggota perorangan tidak
 * punya tempat.
 *
 * Namanya dicocokkan **persis**, jadi jangan diubah jadi "Pribadi/Mandiri"
 * atau semacamnya tanpa ikut mengubah konstantanya.
 *
 * Sisanya contoh ketua kelompok — hapus atau ganti lewat halaman Master Ketua
 * Kelompok. Kodenya mengikuti pola yang dibuat controller
 * (`KKL` + 2 digit tahun + 2 digit bulan + 3 digit urut).
 */
class KetuaKelompokSeeder extends Seeder
{
    public function run(): void
    {
        // Penampung anggota perorangan — bukan data contoh, jangan dihapus.
        GroupLeader::firstOrCreate(
            ['code' => 'PRIBADI'],
            ['name' => GroupLeader::NAMA_PRIBADI]
        );

        $contoh = [
            ['KKL2601001', 'Ahmad Sudrajat', 'L', 'Jl. Melati No. 10, Jakarta Timur', '081234567001'],
            ['KKL2601002', 'Siti Aminah', 'P', 'Jl. Kenanga No. 5, Bekasi', '081234567002'],
            ['KKL2601003', 'Bambang Wijaya', 'L', 'Jl. Anggrek No. 22, Depok', '081234567003'],
        ];

        foreach ($contoh as [$code, $name, $gender, $address, $phone]) {
            GroupLeader::firstOrCreate(['code' => $code], [
                'name' => $name,
                'gender' => $gender,
                'address' => $address,
                'phone' => $phone,
            ]);
        }
    }
}
