<?php

namespace Database\Seeders;

use App\Models\MemberStatus;
use Illuminate\Database\Seeder;

/**
 * Master status anggota.
 *
 * **`STS1` wajib ada.** Form pendaftaran anggota memakainya sebagai status
 * bawaan (`STATUS_BAWAAN` di `app/(app)/nafsul/master/anggota/baru/page.tsx`);
 * tanpa baris ini, setiap pendaftaran anggota baru gagal validasi
 * `exists:member_statuses,code` padahal petugas tidak mengubah apa pun.
 *
 * Kalau kodenya perlu diganti, ubah juga konstanta di halaman itu.
 */
class StatusAnggotaSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['STS1', 'Aktif'],
            ['STS2', 'Nonaktif'],
            ['STS3', 'Meninggal'],
            ['STS4', 'Pindah'],
            ['STS5', 'Mengundurkan Diri'],
        ];

        foreach ($data as [$code, $name]) {
            MemberStatus::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
