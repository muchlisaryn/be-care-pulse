<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timeline tracking order (append-only, tanpa soft-delete). Dikelompokkan per
     * `code_transaction` (invoice) sehingga seluruh rantai handover antar ruangan
     * dapat ditampilkan dalam satu riwayat: dibuat → diterima (di-ACC CSSD) →
     * dipindah (ruangan A → B) → dikembalikan.
     */
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('order')->cascadeOnDelete();
            // Kode transaksi/invoice. Null sampai order diterima CSSD (di-backfill saat receive).
            $table->string('code_transaction')->nullable()->index();
            // dibuat | diterima | dipinjam | dikembalikan | dipindah
            $table->string('type');
            // Ruangan terkait event (tujuan untuk dipindah, asal untuk dibuat/diterima).
            $table->unsignedBigInteger('room_id')->nullable();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
            // Pelaku (nama user yang login) + nama peminjam terkait + catatan bebas.
            $table->string('actor')->nullable();
            $table->string('borrowed_by')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
