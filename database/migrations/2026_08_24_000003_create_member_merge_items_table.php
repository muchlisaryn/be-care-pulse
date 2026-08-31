<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian Gabung Anggota — satu baris = satu rincian transaksi yang berpindah.
 *
 * Dicatat per RINCIAN (`transactions`), bukan per kuitansi, walaupun petugas
 * memilihnya per nomor kuitansi. Sebabnya: yang benar-benar berpindah adalah
 * kolom `transactions.member_id`, dan satu kuitansi bisa memuat rincian milik
 * BEBERAPA anggota — hanya rincian milik anggota asal yang boleh ikut. Mencatat
 * per kuitansi akan mengaburkan justru baris yang tidak ikut pindah.
 *
 * `previous_member_id` disimpan walau selalu sama dengan
 * `member_merges.source_member_id` pada penggabungan biasa: dengan begitu tiap
 * baris berdiri sendiri sebagai perintah "kembalikan rincian ini ke anggota
 * itu", tanpa perlu bergantung pada header yang bisa saja ikut disunting.
 *
 * `transaction_header_id` & `transaction_number` adalah SALINAN keadaan saat
 * penggabungan. Nomor kuitansi disalin sebagai teks supaya riwayat tetap
 * terbaca walau kuitansinya kemudian dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_merge_items')) {
            return;
        }

        Schema::create('member_merge_items', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete: rincian tidak punya arti apa pun tanpa header
            // penggabungannya. Header sendiri hanya dihapus lunak.
            $table->foreignId('member_merge_id')->constrained('member_merges')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();

            // Salinan keadaan saat penggabungan — lihat catatan di atas.
            $table->unsignedBigInteger('transaction_header_id')->nullable();
            $table->string('transaction_number')->nullable();
            $table->unsignedBigInteger('previous_member_id');
            $table->decimal('amount', 15, 2)->default(0);

            // Tujuh kolom audit baku proyek ini (lihat HasAuditColumns).
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->softDeletes();
            $table->string('deleted_by')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();

            // "Rincian ini pernah dipindahkan atau tidak" — dipakai saat
            // menampilkan riwayat sebuah transaksi.
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_merge_items');
    }
};
