<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permintaan pinjam-alih (handover) antar peminjam tanpa order ulang ke CSSD.
     * Peminjam baru meminta sebagian/seluruh unit dari order yang sedang dipinjam;
     * pemegang saat ini (holder_user_id) menyetujui (accept) atau menolak (reject).
     */
    public function up(): void
    {
        Schema::create('order_transfers', function (Blueprint $table) {
            $table->id();
            // Order sumber yang sedang dipinjam (asal unit).
            $table->foreignId('from_order_id')->constrained('order')->cascadeOnDelete();
            // Pemegang saat ini (= from_order.user_id) — penerima request, yang meng-ACC.
            $table->unsignedBigInteger('holder_user_id');
            $table->foreign('holder_user_id')->references('id')->on('users')->cascadeOnDelete();
            // Akun peminjam baru (yang mengajukan request).
            $table->unsignedBigInteger('requested_by_user_id');
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            // Ruangan tujuan + nama peminjam baru (teks bebas).
            $table->foreignId('to_room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('borrowed_by')->nullable();
            $table->text('note')->nullable();
            // pending | accepted | rejected | canceled
            $table->string('status')->default('pending');
            $table->timestamp('responded_at')->nullable();
            // Order baru hasil ACC (tujuan perpindahan unit), diisi saat accepted.
            $table->unsignedBigInteger('new_order_id')->nullable();
            $table->foreign('new_order_id')->references('id')->on('order')->nullOnDelete();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transfers');
    }
};
