<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header transaksi iuran Nafsul.
 *
 * Satu baris = satu kali pembayaran (satu kuitansi), yang bisa menampung
 * banyak baris rincian di tabel `transactions` — mis. satu anggota membayar
 * iuran beberapa bulan sekaligus, atau satu ketua kelompok menyetorkan iuran
 * beberapa anggotanya.
 *
 * Nama kolom memakai bahasa Inggris, mengikuti seluruh tabel Nafsul lainnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_headers', function (Blueprint $table) {
            $table->id();

            /**
             * Kunci publik yang dipakai URL (view/update/delete).
             *
             * `id` yang berurutan tidak pernah keluar dari API: nomornya bisa
             * ditebak, sehingga pengguna bisa mencoba-coba id tetangga untuk
             * mengintip kuitansi milik orang lain. UUID tidak memberi petunjuk
             * apa pun soal jumlah atau urutan data.
             *
             * `id` tetap dipakai sebagai kunci internal & foreign key karena
             * jauh lebih ringan untuk index dibanding string 36 karakter.
             */
            $table->uuid('uuid')->unique();

            /**
             * Nomor transaksi: YYMMDD + urut 3 digit yang dihitung ulang tiap
             * hari, mis. "26082101" → 21 Agustus 2026 transaksi pertama.
             *
             * Unik supaya nomor yang sama tidak pernah dipakai dua kuitansi.
             * Pengecekan di controller memakai `withTrashed()` agar cakupannya
             * sama dengan index ini.
             */
            $table->string('transaction_number', 50)->unique();

            /**
             * Jenis kuitansi: "kelompok" (setoran ketua kelompok untuk
             * anggotanya) atau "pribadi" (anggota perorangan membayar sendiri).
             *
             * Ditaruh di header, bukan di rincian: potongan ketua kelompok dan
             * jasa ketua kelompok hanya berlaku pada setoran kelompok, dan
             * keduanya kolom header. Satu kuitansi karenanya hanya boleh
             * berjenis satu.
             *
             * String biasa, bukan ENUM — alasannya sama dengan `payment_method`.
             */
            $table->string('transaction_type', 20)->index();

            /** Jumlah seluruh rincian sebelum potongan. */
            $table->decimal('total', 15, 2)->default(0);

            /** Potongan yang ditanggung anggota. */
            $table->decimal('member_deduction', 15, 2)->default(0);

            /** Potongan yang ditanggung ketua kelompok. */
            $table->decimal('group_leader_deduction', 15, 2)->default(0);

            /** Jasa/komisi untuk ketua kelompok yang menagihkan. */
            $table->decimal('group_leader_fee', 15, 2)->default(0);

            /** Uang yang benar-benar diterima. */
            $table->decimal('payment', 15, 2)->default(0);

            /**
             * Cara bayar: "transfer" atau "cash".
             *
             * Dipakai string biasa, bukan kolom ENUM: menambah cara bayar baru
             * (mis. QRIS) pada ENUM berarti ALTER TABLE yang mengunci tabel,
             * sedangkan di sini cukup menambah satu nilai di aturan validasi.
             */
            $table->string('payment_method', 20)->index();

            // Kolom audit wajib (trait HasAuditColumns).
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_headers');
    }
};
