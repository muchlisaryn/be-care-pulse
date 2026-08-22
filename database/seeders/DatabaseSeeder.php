<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Isinya sekarang tiga kelompok:
     *   1. STRUKTUR MENU — title + seluruh menu (CSSD, Medis, Pengaturan, Nafsul).
     *      Ini yang jadi bahan otoritas, jadi harus lengkap lebih dulu.
     *   2. AKSES — otoritas "Administrator" (meng-attach SELURUH menu di atas)
     *      lalu user bawaan yang memakainya. Tanpa dua ini database baru tidak
     *      punya akun yang bisa login, sementara menu Otoritas & Pengguna baru
     *      bisa dibuka setelah login.
     *   3. MASTER DATA NAFSUL — isi form anggota & transaksi.
     *
     * Seeder master CSSD (instrumen, ruangan, mesin, printer, kondisi, kategori
     * clinical pathway) sudah dihapus — datanya diisi lewat aplikasi. Yang tersisa
     * di sini hanya menu-nya, supaya otoritas Administrator tetap dapat semua.
     */
    public function run(): void
    {
        $this->call([
            // ---- 1. Struktur menu ----
            TitleMenuSeeder::class,
            MenuSeeder::class,
            // Menu modul Nafsul (grup "Master Nafsul" + turunannya). Setelah
            // TitleMenuSeeder karena menempel di title "Master Data".
            NafsulMenuSeeder::class,
            // Menu tambahan pasca-rilis (idempotent) — aman untuk DB lama & baru.
            RakMenuSeeder::class,
            PengaturanMenuSeeder::class,
            UbahKataSandiMenuSeeder::class,
            LaporanTransaksiInstrumenMenuSeeder::class,

            // ---- 2. Akses ----
            // Otoritas "Administrator" meng-attach SELURUH isi tabel `menus`,
            // jadi wajib setelah semua seeder menu di atas — kalau didahulukan,
            // menu yang lahir belakangan tidak ikut terbagi.
            AuthoritySeeder::class,
            // User bawaan `admin` / `Admin@12345`, memakai otoritas di atas.
            AdminUserSeeder::class,

            // ---- 3. Master data Nafsul ----
            // Dipakai form anggota & transaksi — semuanya firstOrCreate, jadi aman
            // dijalankan berulang dan tidak menimpa data yang sudah disunting petugas.
            PendidikanSeeder::class,
            PekerjaanSeeder::class,
            StatusNikahSeeder::class,
            WilayahSeeder::class,
            KotaSeeder::class,
            // StatusAnggotaSeeder membuat STS1 "Aktif" — status bawaan form
            // pendaftaran anggota. Tanpa itu setiap pendaftaran gagal validasi.
            StatusAnggotaSeeder::class,
            // KetuaKelompokSeeder membuat baris "Pribadi" yang menampung
            // anggota perorangan; dipakai pemisahan pribadi/kelompok.
            KetuaKelompokSeeder::class,
            TarifSeeder::class,
            // Anggota CONTOH — bukan master. Dijalankan paling akhir karena
            // merujuk seluruh master di atas. Hapus lewat halaman Data Anggota
            // begitu data yang sebenarnya masuk.
            AnggotaSeeder::class,
        ]);
    }
}
