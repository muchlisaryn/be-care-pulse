<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahap 5 — Penyimpanan (Storage Management). Mencatat penempatan unit steril
     * ke lokasi rak penyimpanan di ruang steril. Satu baris = satu unit pada satu
     * lokasi rak. `expiry_date` disalin dari batch sterilisasi untuk early-warning.
     */
    public function up(): void
    {
        Schema::create('instrument_storages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('order')->nullOnDelete();
            $table->foreignId('sterilization_id')->nullable()->constrained('sterilizations')->nullOnDelete();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->restrictOnDelete();
            // Kode/label lokasi rak hasil scan (mis. "RAK-A-2" / "Rak A Baris 2").
            $table->string('rack_code');
            // Masa berlaku steril (disalin dari batch) untuk early-warning.
            $table->date('expiry_date')->nullable();
            // tersimpan (default) | keluar (sudah diambil dari gudang)
            $table->string('status')->default('tersimpan');
            $table->timestamp('stored_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['instrument_stock_id', 'status']);
            $table->index(['status', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_storages');
    }
};
