<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BMHP = Bahan Medis Habis Pakai (consumables) yang ikut didistribusikan.
        Schema::create('bmhps', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();              // Auto BMHP-001, BMHP-002, ...
            $table->string('name');
            $table->string('unit')->default('pcs');        // Satuan: pcs/box/dll
            $table->unsignedInteger('stock_qty')->default(0);
            $table->text('description')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bmhps');
    }
};
