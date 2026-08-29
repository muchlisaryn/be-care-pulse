<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat Gabung Anggota — satu baris = satu kali penggabungan.
 *
 * Dibuat sebagai TABEL TERSENDIRI, bukan sekadar kolom di `members`, karena
 * penggabungan bisa terjadi berkali-kali dan sebagian: hari ini 3 kuitansi
 * anggota A dipindah ke B, minggu depan sisanya. Satu kolom di `members` hanya
 * bisa menyimpan yang terakhir, sementara yang dibutuhkan justru seluruhnya —
 * inilah satu-satunya jejak ke mana sebuah transaksi lama berpindah.
 *
 * Rincian per transaksinya ada di `member_merge_items`.
 *
 * Kedua FK memakai `restrictOnDelete`: anggota yang pernah terlibat penggabungan
 * tidak boleh terhapus keras sampai riwayatnya ikut dibereskan. Di proyek ini
 * penghapusan memang selalu lunak, jadi ini pengaman terakhir — bukan jalur
 * yang diharapkan terpakai sehari-hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_merges')) {
            return;
        }

        Schema::create('member_merges', function (Blueprint $table) {
            $table->id();
            // URL memakai uuid, bukan id yang berurutan dan mudah ditebak —
            // sama dengan `transaction_headers` & `transactions`.
            $table->uuid('uuid')->unique();

            // Anggota ASAL: yang transaksinya diambil, dan yang akan dinonaktifkan
            // begitu tidak menyisakan transaksi apa pun.
            $table->foreignId('source_member_id')->constrained('members')->restrictOnDelete();
            // Anggota TUJUAN: pemilik baru transaksi-transaksi itu.
            $table->foreignId('target_member_id')->constrained('members')->restrictOnDelete();

            // Ringkasan yang DIBEKUKAN saat penggabungan terjadi. Bisa saja
            // dihitung ulang dari `member_merge_items`, tapi angka yang dibekukan
            // tetap benar walau rinciannya kemudian disunting atau dihapus —
            // dan riwayat yang berubah angkanya sendiri tidak ada gunanya.
            $table->unsignedInteger('header_count')->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('amount', 15, 2)->default(0);

            // `true` bila penggabungan INI yang membuat anggota asal nonaktif —
            // yaitu ketika transaksi terakhirnya ikut berpindah.
            $table->boolean('source_disabled')->default(false);

            $table->string('note')->nullable();

            // Tujuh kolom audit baku proyek ini (lihat HasAuditColumns).
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->softDeletes();
            $table->string('deleted_by')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();

            // Pertanyaan yang paling sering diajukan ke tabel ini: "anggota ini
            // pernah digabungkan ke mana / dari siapa saja".
            $table->index('source_member_id');
            $table->index('target_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_merges');
    }
};
