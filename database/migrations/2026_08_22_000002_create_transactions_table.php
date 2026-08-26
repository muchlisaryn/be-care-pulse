<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian transaksi iuran anggota Nafsul.
 *
 * Satu baris = iuran seorang anggota untuk satu periode dan satu tarif.
 * Beberapa baris bisa bernaung di bawah satu header (`transaction_headers`)
 * bila dibayar sekaligus dalam satu kuitansi.
 *
 * Nama kolom memakai bahasa Inggris, mengikuti seluruh tabel Nafsul lainnya
 * (`members`, `rates`, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            /**
             * Kunci publik yang dipakai URL (view/update/delete) — alasannya
             * sama dengan `transaction_headers.uuid`.
             */
            $table->uuid('uuid')->unique();

            /**
             * Header pembayarannya — nullable.
             *
             * Rincian bisa dicatat lebih dulu sebagai tagihan yang belum
             * dibayar, lalu menyusul dikaitkan ke header saat pembayarannya
             * terjadi. Kalau kolom ini wajib, tagihan yang belum dibayar tidak
             * punya tempat sama sekali.
             *
             * `nullOnDelete` hanya berlaku pada hard delete (`forceDelete`).
             * Penghapusan biasa lewat controller adalah soft delete, jadi
             * kolom ini TETAP terisi; yang terjadi adalah relasinya tersaring
             * global scope sehingga terbaca null. Kaitannya pulih kembali bila
             * header-nya di-restore — itu sebabnya kolomnya sengaja tidak
             * dikosongkan saat header dihapus.
             */
            $table->foreignId('transaction_header_id')
                ->nullable()
                ->constrained('transaction_headers')
                ->nullOnDelete();

            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('rate_id')->index();

            /**
             * Periode iuran, selalu disimpan sebagai tanggal 1 di bulan itu.
             *
             * Dipakai kolom DATE, bukan sepasang kolom bulan+tahun: dengan satu
             * kolom tanggal, pengurutan kronologis dan filter rentang
             * ("Januari–Juni 2026") jadi perbandingan biasa. Dengan dua kolom
             * integer, keduanya butuh ekspresi gabungan yang tidak bisa
             * memanfaatkan index.
             *
             * Tanggalnya dinormalkan ke hari ke-1 supaya dua baris periode yang
             * sama tidak lolos dari index unik hanya karena beda hari.
             */
            $table->date('payment_period')->index();

            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);

            // Kolom audit wajib (trait HasAuditColumns).
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_user_id')->nullable()->index();

            /**
             * Satu anggota hanya boleh punya satu baris per tarif per periode.
             *
             * Tanpa ini, mengirim ulang form yang sama (atau klik simpan dua
             * kali) diam-diam membuat tagihan ganda untuk bulan yang sama.
             *
             * Index ini mencakup baris yang sudah di-soft-delete, jadi
             * pemeriksaan di controller juga memakai `withTrashed()` agar
             * cakupannya sama.
             */
            $table->unique(['member_id', 'rate_id', 'payment_period'], 'transactions_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
