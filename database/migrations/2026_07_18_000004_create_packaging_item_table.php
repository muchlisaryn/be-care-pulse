<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detail per-unit tahap Packaging. Meniru sterilization_items: satu baris per unit
     * fisik dalam sebuah batch packaging, dengan penanda `disabled`/`disabled_at` agar
     * unit yang di-void (mis. PKG lama saat unit gagal steril diproses ulang) mudah
     * dilacak per unit.
     */
    public function up(): void
    {
        Schema::create('packaging_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packaging_id')->constrained('packaging')->cascadeOnDelete();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->restrictOnDelete();
            $table->string('source')->nullable();        // satuan | paket
            $table->string('package_name')->nullable();  // nama paket (bila source paket)
            $table->boolean('disabled')->default(false); // true = unit di-void di tahap ini
            $table->timestamp('disabled_at')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['packaging_id', 'instrument_stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_item');
    }
};
