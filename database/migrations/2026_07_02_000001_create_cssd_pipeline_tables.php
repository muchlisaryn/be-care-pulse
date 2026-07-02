<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel-tabel tahap pipeline CSSD (produksi → cleaning → packaging → steril).
     * Digabung dalam satu migration. Tahap cleaning (washing) & sterilisasi punya
     * migration create tabelnya sendiri; di sini: production, production_item,
     * packaging, dan pipeline_events (jejak semua user tiap tahap).
     */
    public function up(): void
    {
        // Tahap awal pipeline: Produksi (PRD-NNN). Titik masuk pemrosesan.
        Schema::create('production', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                       // PRD-NNN (auto)
            // Asal batch: internal (produksi CSSD) atau reprocessing (dari order kembali).
            $table->string('source')->default('internal');
            // Code order asal bila reprocessing (teks, bukan FK — order hanya FK di steril).
            $table->string('reference_code')->nullable()->index();
            $table->text('note')->nullable();
            // diproses (default) | selesai
            $table->string('status')->default('diproses');
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

        // Unit fisik yang dikunci ke batch produksi (pengganti order_item di pipeline).
        Schema::create('production_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_id')->constrained('production')->cascadeOnDelete();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->restrictOnDelete();
            // Asal unit: satuan | paket.
            $table->string('source')->default('satuan');
            $table->string('package_name')->nullable();
            // Kondisi unit saat keluar (mengikuti pola order_item.condition_out_id).
            $table->foreignId('condition_out_id')->nullable()->constrained('conditions')->nullOnDelete();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        // Tahap Packaging (PKG-NNN) — dirangkai ke cleaning lewat washing_code.
        Schema::create('packaging', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                       // PKG-NNN (auto)
            // Penghubung ke tahap cleaning sebelumnya (rantai antar-code).
            $table->string('washing_code')->nullable()->index();
            $table->string('operator')->nullable();
            $table->timestamp('packaged_at')->nullable();
            $table->text('note')->nullable();
            // diproses (default) | selesai
            $table->string('status')->default('diproses');
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

        // Jejak lengkap (append-only) semua user yang memproses tiap tahap sampai selesai.
        Schema::create('pipeline_events', function (Blueprint $table) {
            $table->id();
            // Tahap: production | washing | packaging | sterilization.
            $table->string('stage')->index();
            // Code record tahap terkait (PRD/WSH/PKG/STR-NNN).
            $table->string('code')->index();
            // Aksi: dibuat | diproses | selesai | gagal | dst.
            $table->string('action');
            // Username pelaku aksi.
            $table->string('actor')->nullable();
            $table->text('note')->nullable();
            // Append-only: hanya created_at, tanpa updated_at.
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_events');
        Schema::dropIfExists('packaging');
        Schema::dropIfExists('production_item');
        Schema::dropIfExists('production');
    }
};
