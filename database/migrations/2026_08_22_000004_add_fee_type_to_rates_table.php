<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sifat tarif: `one_time` (sekali bayar) atau `recurring` (berulang tiap periode).
 *
 * Memisahkan iuran rutin — yang nominalnya dikalikan jumlah bulan di halaman
 * Transaksi — dari pungutan/pengeluaran yang hanya sekali. Sebelumnya keduanya
 * hanya dibedakan lewat nama tarif, jadi tidak bisa diandalkan program.
 *
 * Sengaja NULLABLE tanpa default: baris lama dibiarkan kosong (= belum
 * diklasifikasi) supaya TarifSeeder bisa mengisinya tanpa menimpa pilihan yang
 * sudah ditentukan petugas lewat halaman Master Tarif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rates', function (Blueprint $table) {
            if (! Schema::hasColumn('rates', 'fee_type')) {
                $table->string('fee_type')->nullable()->index()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rates', function (Blueprint $table) {
            if (Schema::hasColumn('rates', 'fee_type')) {
                $table->dropColumn('fee_type');
            }
        });
    }
};
