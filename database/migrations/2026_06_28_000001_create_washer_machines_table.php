<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master mesin pencuci (washer disinfector) pada tahap Cleaning & Disinfection.
     * Setiap mesin punya kode/barcode (auto WSH-NNN) yang dipindai petugas sebelum
     * alat masuk mesin, serta ambang batas suhu & durasi yang dipakai sistem untuk
     * mendeteksi kegagalan parameter pencucian.
     */
    public function up(): void
    {
        Schema::create('washer_machines', function (Blueprint $table) {
            $table->id();
            // Kode/barcode mesin (auto WSH-NNN).
            $table->string('code')->unique();
            $table->string('name');
            // Lokasi penempatan mesin (opsional).
            $table->string('location')->nullable();
            // Ambang batas suhu (°C) — di luar rentang ini dianggap gagal.
            $table->decimal('min_temperature', 5, 2)->nullable();
            $table->decimal('max_temperature', 5, 2)->nullable();
            // Ambang batas durasi pencucian (menit).
            $table->unsignedInteger('min_duration_minutes')->nullable();
            $table->unsignedInteger('max_duration_minutes')->nullable();
            // aktif (default) | nonaktif
            $table->string('status')->default('aktif');
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('washer_machines');
    }
};
