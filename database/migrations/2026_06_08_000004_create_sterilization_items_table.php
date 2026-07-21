<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sterilization_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sterilization_id')->constrained('sterilizations')->cascadeOnDelete();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->restrictOnDelete();
            $table->string('result')->nullable(); // Hasil per unit: lulus/gagal (opsional, default ikut batch)
            // Unit gagal yang di-void (dikembalikan ke packaging) — mudah dilacak per unit.
            $table->boolean('disabled')->default(false);
            $table->timestamp('disabled_at')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['sterilization_id', 'instrument_stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sterilization_items');
    }
};
