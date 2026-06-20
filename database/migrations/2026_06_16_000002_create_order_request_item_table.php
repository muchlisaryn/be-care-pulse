<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris permintaan order (hanya jumlah). Unit fisik (order_item) baru
     * di-generate saat CSSD menerima pesanan.
     */
    public function up(): void
    {
        Schema::create('order_request_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('order')->cascadeOnDelete();
            // Jenis permintaan: `satuan` (per jenis instrumen) atau `paket` (per katalog paket).
            $table->string('type');
            // Untuk satuan: jenis instrumen yang diminta.
            $table->unsignedBigInteger('instrument_id')->nullable();
            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
            // Untuk paket: katalog paket yang diminta + snapshot namanya.
            $table->unsignedBigInteger('instrument_catalog_id')->nullable();
            $table->foreign('instrument_catalog_id')->references('id')->on('instrument_catalogs')->nullOnDelete();
            $table->string('package_name')->nullable();
            // Jumlah yang diminta (jumlah unit untuk satuan, jumlah set untuk paket).
            $table->unsignedInteger('quantity')->default(1);
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_request_item');
    }
};
