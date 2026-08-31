<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buang `member_deduction_type` & `member_deduction_input` dari kuitansi.
 *
 * Keduanya dulu menyimpan cara petugas MENGETIK potongan anggota (rupiah atau
 * persen) beserta angka ketiknya, sementara nominal rupiahnya tetap disimpan di
 * `member_deduction`. Tiga kolom untuk satu angka ternyata cuma peluang saling
 * berselisih: form transaksi sudah lama mengirim potongan nol (potongan bulan
 * gratis dipotong di tiap baris rincian), dan impor selalu rupiah karena
 * templatnya memang tidak punya kolom satuan.
 *
 * `member_deduction` — nominal rupiah yang dipakai `balance` — TIDAK ikut
 * dibuang; ia satu-satunya yang benar-benar dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            foreach (['member_deduction_input', 'member_deduction_type'] as $kolom) {
                if (Schema::hasColumn('transaction_headers', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_headers', 'member_deduction_type')) {
                $table->string('member_deduction_type', 10)
                    ->default('amount')
                    ->after('member_deduction');
            }

            if (! Schema::hasColumn('transaction_headers', 'member_deduction_input')) {
                $table->decimal('member_deduction_input', 15, 4)
                    ->default(0)
                    ->after('member_deduction_type');
            }
        });

        // Angka ketiknya tidak bisa dipulihkan apa adanya — yang tersisa cuma
        // nominal rupiahnya. Diisikan sebagai satuan rupiah, satu-satunya
        // pembacaan yang pasti benar untuk seluruh baris.
        DB::table('transaction_headers')->update([
            'member_deduction_type' => 'amount',
            'member_deduction_input' => DB::raw('member_deduction'),
        ]);
    }
};
