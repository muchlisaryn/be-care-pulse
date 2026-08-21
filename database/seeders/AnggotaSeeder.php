<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Education;
use App\Models\GroupLeader;
use App\Models\Member;
use App\Models\MemberFamily;
use App\Models\MemberStatus;
use App\Models\Occupation;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Anggota contoh Nafsul.
 *
 * Berbeda dari seeder master lain, ini **data contoh** — hapus lewat halaman
 * Data Anggota begitu data anggota yang sebenarnya masuk (lewat impor Excel
 * atau pendaftaran manual).
 *
 * Gunanya: halaman Transaksi, statistik pribadi/kelompok, dan dropdown anggota
 * tidak bisa dicoba sama sekali selama tabel `members` kosong.
 *
 * Bergantung pada master yang sudah di-seed lebih dulu (wilayah, kota, status,
 * ketua kelompok, pendidikan, pekerjaan). Kalau salah satunya belum ada,
 * kolomnya dibiarkan null daripada seeder-nya gagal — anggota tetap terbentuk.
 */
class AnggotaSeeder extends Seeder
{
    /**
     * Nomor anggota ditulis eksplisit, bukan dibuat generator di controller.
     *
     * Generator memakai tanggal hari ini, jadi menjalankan seeder di hari
     * berbeda akan menghasilkan nomor berbeda untuk orang yang sama — dan
     * `firstOrCreate` tidak lagi mengenalinya sebagai baris yang sudah ada,
     * lalu membuat duplikat. Nomor tetap membuat seeder ini aman diulang.
     *
     * Formatnya sama seperti yang dibuat aplikasi: YYMMDD + urut 2 digit.
     */
    private const ANGGOTA = [
        // [no_anggota, nama, L/P, tgl_lahir, kode_wilayah, kota_lahir, pendidikan, pekerjaan, status_nikah, ketua, status, tgl_aktif]
        ['26010101', 'Ahmad Fauzi', 'L', '1985-03-12', '05', 'KT05', 'S1', 'Karyawan', 'Menikah', 'KKL2601001', 'STS1', '2026-01-01'],
        ['26010102', 'Siti Nurhaliza', 'P', '1990-07-25', '05', 'KT05', 'SLTA', 'IRT', 'Menikah', 'KKL2601001', 'STS1', '2026-01-01'],
        ['26010103', 'Budi Santoso', 'L', '1978-11-03', '06', 'KT06', 'DIPLOMA', 'Wiraswasta', 'Menikah', 'KKL2601001', 'STS1', '2026-01-01'],
        ['26010104', 'Dewi Lestari', 'P', '1992-02-18', '06', 'KT11', 'S1', 'Guru', 'Menikah', 'KKL2601001', 'STS1', '2026-01-01'],
        ['26010105', 'Eko Prasetyo', 'L', '1983-09-30', '07', 'KT07', 'SLTA', 'Pedagang', 'Menikah', 'KKL2601002', 'STS1', '2026-01-01'],
        ['26010106', 'Fitri Handayani', 'P', '1995-05-14', '07', 'KT20', 'S1', 'Bidan', 'Lajang', 'KKL2601002', 'STS1', '2026-01-01'],
        ['26010107', 'Gunawan Wibisono', 'L', '1970-12-08', '04', 'KT04', 'S2', 'PNS', 'Menikah', 'KKL2601002', 'STS1', '2026-01-01'],
        ['26010108', 'Hendra Kusuma', 'L', '1988-04-22', '04', 'KT15', 'SLTA', 'Sopir', 'Menikah', 'KKL2601002', 'STS1', '2026-01-01'],
        ['26010109', 'Indah Permatasari', 'P', '1993-08-17', '03', 'KT03', 'S1', 'IT', 'Lajang', 'KKL2601003', 'STS1', '2026-01-01'],
        ['26010110', 'Joko Susilo', 'L', '1975-06-05', '03', 'KT16', 'SMP', 'Buruh', 'Menikah', 'KKL2601003', 'STS1', '2026-01-01'],
        ['26010111', 'Kartika Sari', 'P', '1998-01-29', '08', 'KT08', 'SLTA', 'Pelajar', 'Lajang', 'KKL2601003', 'STS1', '2026-01-01'],
        // Anggota perorangan — ketuanya "Pribadi", terhitung tipe pribadi.
        ['26010112', 'Lukman Hakim', 'L', '1980-10-11', '01', 'KT01', 'S1', 'Dosen', 'Menikah', 'PRIBADI', 'STS1', '2026-01-01'],
        ['26010113', 'Maya Anggraini', 'P', '1987-03-07', '02', 'KT02', 'DIPLOMA', 'Karyawan', 'Janda', 'PRIBADI', 'STS1', '2026-01-01'],
        // Dua status non-aktif supaya penyaringan status ada isinya saat dicoba.
        ['26010114', 'Nur Cahyono', 'L', '1965-07-19', '05', 'KT21', 'SLTA', 'Pensiuanan', 'Menikah', 'KKL2601001', 'STS2', '2026-01-01'],
        ['26010115', 'Oman Suparman', 'L', '1958-02-02', '06', 'KT25', 'SD', 'Lain-Lain', 'Duda', 'KKL2601002', 'STS3', '2026-01-01'],
    ];

    /** [no_anggota induk, nama keluarga, L/P, tgl_lahir, pendidikan] */
    private const KELUARGA = [
        ['26010101', 'Nabila Fauzi', 'P', '2012-05-04', 'SD'],
        ['26010101', 'Rizky Fauzi', 'L', '2015-09-21', 'SD'],
        ['26010103', 'Bayu Santoso', 'L', '2008-01-30', 'SMP'],
        ['26010107', 'Galih Wibisono', 'L', '2003-11-12', 'SLTA'],
    ];

    public function run(): void
    {
        // Master dipetakan sekali di depan: tanpa ini tiap baris anggota
        // menembak database berkali-kali hanya untuk menerjemahkan kode.
        $wilayah = Region::pluck('id', 'code');
        $kota = City::pluck('id', 'code');
        $status = MemberStatus::pluck('id', 'code');
        $ketua = GroupLeader::pluck('id', 'code');
        $pendidikan = Education::pluck('id', 'name');
        $pekerjaan = Occupation::pluck('id', 'name');

        foreach (self::ANGGOTA as [
            $nomor, $nama, $gender, $lahir, $kodeWilayah, $kodeKota,
            $namaPendidikan, $namaPekerjaan, $nikah, $kodeKetua, $kodeStatus, $aktif,
        ]) {
            Member::firstOrCreate(['member_number' => $nomor], [
                'name' => $nama,
                'gender' => $gender,
                'birth_date' => $lahir,
                'region_id' => $wilayah[$kodeWilayah] ?? null,
                'birth_city_id' => $kota[$kodeKota] ?? null,
                'education_id' => $pendidikan[$namaPendidikan] ?? null,
                'occupation_id' => $pekerjaan[$namaPekerjaan] ?? null,
                'marital_status' => $nikah,
                'group_leader_id' => $ketua[$kodeKetua] ?? null,
                'member_status_id' => $status[$kodeStatus] ?? null,
                'active_date' => $aktif,
            ]);
        }

        $anggota = Member::whereIn('member_number', array_column(self::ANGGOTA, 0))
            ->pluck('id', 'member_number');

        foreach (self::KELUARGA as $i => [$nomorInduk, $namaAnggota, $gender, $lahir, $didik]) {
            if (! isset($anggota[$nomorInduk])) {
                continue;
            }

            // Nomor keluarga mengikuti pola aplikasi: nomor induk + urut 2 digit.
            $urut = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);

            MemberFamily::firstOrCreate(
                [
                    'member_id' => $anggota[$nomorInduk],
                    'member_name' => $namaAnggota,
                ],
                [
                    'member_number' => $nomorInduk.'-'.$urut,
                    'birth_date' => $lahir,
                    'gender' => $gender,
                    'education' => $didik,
                ]
            );
        }
    }
}
