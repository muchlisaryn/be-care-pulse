<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            TitleMenuSeeder::class,
            MenuSeeder::class,
            // Menu Nafsul harus dibuat SEBELUM AuthoritySeeder agar authority
            // "Administrator" (yang meng-attach seluruh menu) ikut mendapatkannya.
            NafsulMenuSeeder::class,
            AuthoritySeeder::class,
            // Menu tambahan pasca-rilis (idempotent) — aman untuk DB lama & baru.
            RakMenuSeeder::class,
            PengaturanMenuSeeder::class,
            UbahKataSandiMenuSeeder::class,
            LaporanTransaksiInstrumenMenuSeeder::class,
            PrinterSeeder::class,
            RoomSeeder::class,
            ConditionSeeder::class,
            InstrumentCatalogSeeder::class,
            // Tautkan ulang gambar instrumen dari berkas yatim di public/uploads
            // (referensi DB hilang tiap migrate:fresh, filenya tetap ada).
            InstrumentImageSeeder::class,
            InstrumentStockSeeder::class,
            // Master mesin pipeline CSSD (cleaning & sterilisasi).
            WasherMachineSeeder::class,
            SterilizerMachineSeeder::class,
            CategoriClinicalPathwaySeeder::class,
            // Master data Nafsul (dipakai form anggota & transaksi) —
            // semuanya firstOrCreate, jadi aman dijalankan berulang dan tidak
            // menimpa data yang sudah disunting petugas.
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
