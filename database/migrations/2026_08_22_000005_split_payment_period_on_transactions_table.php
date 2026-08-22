<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `transactions.payment_period` (DATE) dipecah jadi `month` + `year`.
 *
 * Migrasi pembuat tabel sengaja memilih satu kolom DATE supaya pengurutan dan
 * filter rentang jadi perbandingan biasa. Alasan itu gugur begitu tarif
 * `one_time` masuk: pungutan sekali bayar tidak punya periode sama sekali, dan
 * satu kolom DATE memaksanya diisi tanggal karangan yang tidak berarti apa-apa
 * tapi tetap ikut terurut dan terhitung. Dengan dua kolom yang boleh NULL,
 * "tidak punya periode" bisa dinyatakan apa adanya.
 *
 * Filter rentang tetap bisa memakai index — bukan lewat ekspresi gabungan
 * (`year * 100 + month`) yang mematikan index, melainkan perbandingan bertingkat
 * `year > ? OR (year = ? AND month >= ?)` yang masih memanfaatkan index gabungan
 * (`year`, `month`). Lihat TransaksiController::filterPeriode().
 *
 * Kontrak API tidak berubah: `payment_period` tetap dikirim & diterima sebagai
 * "MM/YYYY" (kini `null` untuk tarif sekali bayar), jadi frontend tidak perlu
 * menyesuaikan tampilannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'payment_period')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedTinyInteger('month')->nullable()->after('rate_id');
            $table->unsignedSmallInteger('year')->nullable()->after('month');
        });

        // Baris lama selalu punya periode — dipindahkan apa adanya.
        DB::table('transactions')->update([
            'month' => DB::raw('MONTH(payment_period)'),
            'year' => DB::raw('YEAR(payment_period)'),
        ]);

        Schema::table('transactions', function (Blueprint $table) {
            // Index unik lama menyebut payment_period, jadi harus dilepas
            // sebelum kolomnya bisa dibuang.
            $table->dropUnique('transactions_unik');
            $table->dropIndex(['payment_period']);
            $table->dropColumn('payment_period');
        });

        Schema::table('transactions', function (Blueprint $table) {
            /**
             * Aturan lama dipertahankan: satu anggota hanya boleh punya satu
             * baris per tarif per periode.
             *
             * Catatan penting soal baris sekali bayar: MySQL menganggap NULL
             * tidak pernah sama dengan NULL, sehingga index ini TIDAK membatasi
             * baris ber-`month`/`year` NULL. Itu memang yang diinginkan —
             * pungutan sekali bayar boleh dicatat lebih dari sekali untuk
             * anggota & tarif yang sama.
             */
            $table->unique(['member_id', 'rate_id', 'month', 'year'], 'transactions_unik');

            // Urutan kolomnya (tahun dulu, baru bulan) mengikuti cara barisnya
            // diurutkan & disaring; dibalik, index-nya tidak terpakai.
            $table->index(['year', 'month'], 'transactions_periode_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'payment_period')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->date('payment_period')->nullable()->after('rate_id');
        });

        // Baris sekali bayar tidak punya periode; dipatok ke tanggal pembuatannya
        // supaya kolomnya bisa dikembalikan menjadi NOT NULL seperti semula.
        DB::table('transactions')->whereNotNull('month')->update([
            'payment_period' => DB::raw("DATE_FORMAT(CONCAT(year, '-', LPAD(month, 2, '0'), '-01'), '%Y-%m-%d')"),
        ]);

        DB::table('transactions')->whereNull('month')->update([
            'payment_period' => DB::raw('DATE_FORMAT(created_at, "%Y-%m-01")'),
        ]);

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_unik');
            $table->dropIndex('transactions_periode_index');
            $table->dropColumn(['month', 'year']);
            $table->date('payment_period')->nullable(false)->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('payment_period');
            $table->unique(['member_id', 'rate_id', 'payment_period'], 'transactions_unik');
        });
    }
};
