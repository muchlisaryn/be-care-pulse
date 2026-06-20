<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot unit (instrument_stock) yang diminta dalam satu permintaan transfer.
     * Disimpan per-unit agar peminjam baru bisa memilih paket utuh maupun unit satuan.
     */
    public function up(): void
    {
        Schema::create('order_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_transfer_id')->constrained('order_transfers')->cascadeOnDelete();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->cascadeOnDelete();
            // Asal unit pada order sumber: `satuan` atau `paket` (+ nama paketnya).
            $table->string('source')->default('satuan');
            $table->string('package_name')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transfer_items');
    }
};
