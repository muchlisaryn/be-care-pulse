<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyelesaian bentrok periode saat Gabung Anggota.
 *
 * Sebelumnya penggabungan DITOLAK begitu anggota tujuan sudah punya transaksi
 * untuk tarif & periode yang sama — index unik `transactions_unik` melarangnya,
 * dan tidak ada jalan lain selain petugas membereskan datanya lebih dulu.
 *
 * Sekarang ada jalan kedua yang bisa dipilih: rincian anggota ASAL yang bentrok
 * DINONAKTIFKAN (soft delete → `transactions.disabled` = true) alih-alih
 * dipindahkan. Yang dipertahankan adalah milik anggota tujuan, karena itulah
 * baris yang akan terus dipakai; keduanya mencatat setoran yang sama, jadi
 * memindahkan yang kedua hanya akan menggandakannya.
 *
 * Dua kolom yang ditambahkan:
 *
 *  - `member_merge_items.action` — `moved` (dipindahkan) atau `disabled`
 *    (dinonaktifkan karena bentrok). Tanpa ini riwayat penggabungan tidak bisa
 *    membedakan rincian yang berpindah dari yang dibuang, padahal keduanya
 *    tercatat sebagai baris yang sama-sama "ikut dalam penggabungan ini".
 *  - `member_merges.disabled_count` — berapa rincian yang dinonaktifkan.
 *    Dibekukan seperti angka ringkasan lainnya di tabel itu.
 *
 * Baris lama diisi `moved`: sebelum migrasi ini, menonaktifkan rincian memang
 * belum mungkin, jadi seluruh riwayat yang sudah ada pasti berupa pemindahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('member_merge_items', 'action')) {
            Schema::table('member_merge_items', function (Blueprint $table) {
                $table->string('action')->default('moved')->after('transaction_id');
            });
        }

        if (! Schema::hasColumn('member_merges', 'disabled_count')) {
            Schema::table('member_merges', function (Blueprint $table) {
                $table->unsignedInteger('disabled_count')->default(0)->after('transaction_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('member_merge_items', 'action')) {
            Schema::table('member_merge_items', function (Blueprint $table) {
                $table->dropColumn('action');
            });
        }

        if (Schema::hasColumn('member_merges', 'disabled_count')) {
            Schema::table('member_merges', function (Blueprint $table) {
                $table->dropColumn('disabled_count');
            });
        }
    }
};
