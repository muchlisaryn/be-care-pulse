<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan pencucian (Cleaning) — satu batch per order. Diisi operator CSSD di
     * menu "Cleaning & Pengemasan": nomor mesin, ID operator, suhu, waktu, dan
     * jenis deterjen/enzimatis. Status: dalam_proses → selesai (Selesai Cuci).
     */
    public function up(): void
    {
        Schema::create('order_washing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('order')->cascadeOnDelete();
            // Nomor mesin pencuci & ID/nama operator (teks bebas, seperti borrowed_by).
            $table->string('machine_no')->nullable();
            $table->string('operator')->nullable();
            // Suhu pencucian (°C) — disimpan sebagai teks agar fleksibel (mis. "60").
            $table->string('temperature')->nullable();
            // Waktu pencucian (kapan dicuci).
            $table->timestamp('washed_at')->nullable();
            // Jenis deterjen / enzimatis yang dipakai.
            $table->string('detergent_type')->nullable();
            // dalam_proses (default) | selesai
            $table->string('status')->default('dalam_proses');
            // Kapan ditandai "Selesai Cuci".
            $table->timestamp('completed_at')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_washing');
    }
};
