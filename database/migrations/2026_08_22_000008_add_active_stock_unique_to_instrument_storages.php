<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jaminan tingkat database: satu unit fisik hanya boleh punya SATU baris rak
 * yang aktif.
 *
 * Tanpa ini, aturan tersebut hanya hidup di kode (`StorageController::storeProduction`
 * memeriksa status unit harus `tersedia`). Pemeriksaan itu baca-lalu-tulis: dua
 * permintaan yang berjalan bersamaan bisa sama-sama lolos lalu sama-sama
 * menyimpan, dan satu instrumen muncul dua kali sebagai stok steril — atau
 * muncul di gudang padahal sedang dipinjam unit lain, sehingga bisa terpesan
 * dua kali.
 *
 * MySQL tidak punya partial unique index, jadi dipakai kolom turunan:
 * `active_stock_id` bernilai `instrument_stock_id` HANYA saat barisnya aktif,
 * selain itu NULL. MySQL menganggap setiap NULL berbeda pada index unik,
 * sehingga baris riwayat (sudah dihapus / keluar rak / dipesan) tidak ikut
 * bertabrakan — yang dijaga hanya baris yang benar-benar sedang di rak.
 *
 * Definisi "aktif" di sini WAJIB sama dengan `InstrumentStorage::sterilePool()`.
 * Bila salah satunya diubah tanpa yang lain, index ini menjaga himpunan baris
 * yang berbeda dari yang ditampilkan halaman Gudang Steril.
 */
return new class extends Migration
{
    private const KOLOM = 'active_stock_id';

    private const INDEX = 'instrument_storages_active_stock_unique';

    public function up(): void
    {
        if (Schema::hasColumn('instrument_storages', self::KOLOM)) {
            return;
        }

        // Gagal lebih awal dengan pesan yang bisa ditindaklanjuti — lebih baik
        // daripada galat MySQL mentah yang tidak menyebut unit mana yang bentrok.
        $duplikat = DB::table('instrument_storages')
            ->select('instrument_stock_id', DB::raw('COUNT(*) AS jumlah'))
            ->whereNull('deleted_by')
            ->whereNull('removed_at')
            ->whereNull('order_id')
            ->groupBy('instrument_stock_id')
            ->having('jumlah', '>', 1)
            ->pluck('jumlah', 'instrument_stock_id');

        if ($duplikat->isNotEmpty()) {
            $contoh = $duplikat->take(10)
                ->map(fn ($jumlah, $stockId) => "unit#{$stockId} ({$jumlah} baris)")
                ->implode(', ');

            throw new RuntimeException(
                'Tidak bisa memasang index unik: ada '.$duplikat->count().
                ' unit yang punya lebih dari satu baris rak aktif. Rapikan dulu datanya. Contoh: '.$contoh
            );
        }

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->unsignedBigInteger(self::KOLOM)
                ->nullable()
                // VIRTUAL, bukan STORED: MySQL 8 menolak menambah kolom stored
                // lewat ALTER in-place pada tabel yang punya foreign key
                // (galat 1215). Kolom virtual tetap boleh diindeks, dan karena
                // nilainya cuma disalin dari kolom lain, tidak ada biaya
                // penyimpanan yang hilang.
                ->virtualAs(
                    'CASE WHEN deleted_by IS NULL AND removed_at IS NULL AND order_id IS NULL '.
                    'THEN instrument_stock_id END'
                );
        });

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->unique(self::KOLOM, self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('instrument_storages', self::KOLOM)) {
            return;
        }

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropColumn(self::KOLOM);
        });
    }
};
