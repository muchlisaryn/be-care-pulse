<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan anggota boleh diisi sebagai RUPIAH atau PERSEN.
 *
 * `member_deduction` (yang sudah ada) tetap menyimpan hasil akhirnya dalam
 * rupiah dan tetap jadi satu-satunya dasar perhitungan `balance`. Dua kolom di
 * sini hanya merekam CARA petugas mengisinya.
 *
 * Kenapa rupiahnya tetap disimpan, bukan dihitung ulang dari persen tiap kali
 * dibaca: total kuitansi bisa berubah kalau rinciannya diedit, dan potongan
 * yang dihitung ulang akan ikut bergeser tanpa disadari — kuitansi yang sudah
 * tercetak jadi tidak cocok lagi dengan yang tersimpan.
 *
 * Kenapa persennya ikut disimpan, bukan dihitung mundur `rupiah ÷ total`:
 * hasilnya bisa meleset karena pembulatan, dan tidak bisa dihitung sama sekali
 * kalau totalnya nol. Alasan yang sama dengan `group_leader_fee_percent`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_headers', 'member_deduction_type')) {
                // "amount" = rupiah, "percent" = persen dari total rincian.
                // String biasa, bukan ENUM — alasannya sama dengan `payment_method`.
                $table->string('member_deduction_type', 10)
                    ->default('amount')
                    ->after('member_deduction');
            }

            if (! Schema::hasColumn('transaction_headers', 'member_deduction_input')) {
                // Angka yang diketik apa adanya: 5 untuk "5%", 25000 untuk "Rp 25.000".
                // 4 desimal supaya persen pecahan (mis. 2,5%) tidak dibulatkan.
                $table->decimal('member_deduction_input', 15, 4)
                    ->default(0)
                    ->after('member_deduction_type');
            }
        });

        // Baris lama: potongannya sudah berupa rupiah, jadi nilai ketiknya sama
        // dengan nominalnya. Tanpa ini form menampilkan 0 untuk kuitansi lama
        // yang sebenarnya punya potongan.
        Schema::hasColumn('transaction_headers', 'member_deduction_input')
            && \Illuminate\Support\Facades\DB::table('transaction_headers')
                ->where('member_deduction_input', 0)
                ->where('member_deduction', '>', 0)
                ->update([
                    'member_deduction_input' => \Illuminate\Support\Facades\DB::raw('member_deduction'),
                ]);
    }

    public function down(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            foreach (['member_deduction_type', 'member_deduction_input'] as $kolom) {
                if (Schema::hasColumn('transaction_headers', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
