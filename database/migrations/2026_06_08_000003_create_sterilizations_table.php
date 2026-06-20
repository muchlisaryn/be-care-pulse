<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sterilizations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                  // Auto STR-001, STR-002, ...
            $table->string('machine');                         // Nama/no. mesin autoclave
            $table->string('method')->default('uap');          // uap | eo | plasma | panas_kering
            $table->string('cycle_number')->nullable();        // No. siklus pada mesin
            $table->decimal('temperature', 5, 2)->nullable();  // Suhu (°C)
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('operator')->nullable();            // Operator pelaksana
            $table->dateTime('sterilized_at');                 // Waktu proses sterilisasi
            $table->date('expiry_date')->nullable();           // Masa berlaku steril
            $table->string('chemical_indicator')->nullable();  // Hasil indikator kimia: lulus/gagal
            $table->string('biological_indicator')->nullable(); // Hasil indikator biologis: pending/lulus/gagal
            $table->string('status')->default('diproses');     // diproses | selesai | gagal
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sterilizations');
    }
};
