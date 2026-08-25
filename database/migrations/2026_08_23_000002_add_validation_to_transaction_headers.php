<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validasi kuitansi iuran Nafsul — jejak SIAPA yang memeriksa dan KAPAN.
 *
 * Kuitansi dibuat petugas input, lalu diperiksa ulang oleh pemeriksa sebelum
 * dianggap sah. Sebelum ini tidak ada tempat merekam pemeriksaan itu: begitu
 * kuitansi tersimpan, tidak ada bedanya antara yang sudah diperiksa dan yang
 * belum.
 *
 * Dua kolom, bukan satu boolean `validated`. Boolean hanya menjawab "sudah atau
 * belum", sedangkan yang dibutuhkan saat ada selisih justru "oleh siapa dan
 * kapan". `validation_at` NULL = belum divalidasi, jadi statusnya tetap terbaca
 * dari satu kolom tanpa perlu penanda terpisah.
 *
 * `validation_by` menyimpan NAMA pengguna, bukan foreign key ke `users` —
 * mengikuti pola kolom audit `created_by`/`updated_by` di seluruh proyek ini:
 * nama yang tercatat pada kuitansi lama tidak boleh ikut berubah bila akun
 * pemeriksanya di-rename atau dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_headers', 'validation_at')) {
                $table->timestamp('validation_at')->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('transaction_headers', 'validation_by')) {
                $table->string('validation_by')->nullable()->after('validation_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            foreach (['validation_at', 'validation_by'] as $kolom) {
                if (Schema::hasColumn('transaction_headers', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
