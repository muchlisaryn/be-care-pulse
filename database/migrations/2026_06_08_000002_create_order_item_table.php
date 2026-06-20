<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('order')->cascadeOnDelete();
            $table->foreignId('instrument_stock_id')->constrained('instrument_stocks')->restrictOnDelete();
            $table->unsignedBigInteger('condition_out_id')->nullable();
            $table->foreign('condition_out_id')->references('id')->on('conditions')->nullOnDelete();
            $table->unsignedBigInteger('condition_in_id')->nullable();
            $table->foreign('condition_in_id')->references('id')->on('conditions')->nullOnDelete();
            $table->boolean('is_returned')->default(false);
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item');
    }
};
