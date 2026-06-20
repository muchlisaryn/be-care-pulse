<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log append-only riwayat perubahan status unit instrumen (tidak pakai soft delete).
        Schema::create('instrument_stock_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('context')->nullable();        // create | manual | order | sterilization | set
            $table->string('reference_code')->nullable();  // mis. ORD-001, STR-001, SET-001
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_stock_logs');
    }
};
