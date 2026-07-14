<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master mesin sterilisator (autoclave) pada tahap Sterilization. Setiap mesin
     * punya kode auto STL-NNN, serta suhu & durasi standar dan masa simpan steril
     * (hari) yang dipakai sebagai acuan saat memvalidasi hasil sterilisasi.
     */
    public function up(): void
    {
        Schema::create('sterilizer_machines', function (Blueprint $table) {
            $table->id();
            // Kode mesin (auto STL-NNN).
            $table->string('code')->unique();
            $table->string('name');
            // Lokasi penempatan mesin (opsional).
            $table->string('location')->nullable();
            // Suhu (°C) & durasi (menit) standar mesin.
            $table->decimal('temperature', 5, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            // Masa simpan steril (hari) untuk alat yang disterilkan di mesin ini.
            $table->unsignedInteger('sterile_shelf_life_days')->nullable();
            // aktif (default) | nonaktif
            $table->string('status')->default('aktif');
            $table->text('note')->nullable();
            // Kolom audit standar (lihat HasAuditColumns).
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sterilizer_machines');
    }
};
