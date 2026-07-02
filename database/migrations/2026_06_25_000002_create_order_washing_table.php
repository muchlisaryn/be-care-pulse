<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahap Cleaning pada pipeline CSSD (produksi → cleaning → packaging → steril).
     * Tabel mandiri: TIDAK menyimpan order_id — keterkaitan ke order hanya ada di
     * tahap sterilisasi. Antar-tahap dirangkai lewat code: washing.production_code
     * menunjuk ke code tahap produksi sebelumnya. Diisi operator CSSD di menu
     * Cleaning: nomor mesin, operator, suhu, waktu, deterjen. Status: dalam_proses
     * → selesai / gagal.
     */
    public function up(): void
    {
        Schema::create('washing', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                        // WSH-NNN (auto)
            // Penghubung ke tahap produksi sebelumnya (rantai antar-code).
            $table->string('production_code')->nullable()->index();
            // Nomor mesin pencuci & ID/nama operator (teks bebas, seperti borrowed_by).
            $table->string('machine_no')->nullable();
            $table->string('operator')->nullable();
            // Suhu pencucian (°C) — disimpan sebagai teks agar fleksibel (mis. "60").
            $table->string('temperature')->nullable();
            // Waktu pencucian (kapan dicuci).
            $table->timestamp('washed_at')->nullable();
            // Jenis deterjen / enzimatis yang dipakai.
            $table->string('detergent_type')->nullable();
            // dalam_proses (default) | selesai | gagal
            $table->string('status')->default('dalam_proses');
            // Jejak user per tahap: yang memulai & yang menyelesaikan.
            $table->string('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->string('completed_by')->nullable();
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
        Schema::dropIfExists('washing');
    }
};
