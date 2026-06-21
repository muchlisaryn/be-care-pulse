<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('template_clinical_pathway', function (Blueprint $table) {
            $table->id();
            // Diagnosa diambil dari tabel icd10.
            $table->foreignId('icd10_id')->constrained('icd10')->cascadeOnDelete();
            $table->integer('maksimal_hari');
            $table->text('keterangan')->nullable();
            // Status aktif / tidak. Template tidak bisa dihapus, hanya di-nonaktifkan.
            $table->boolean('is_active')->default(true);
            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_clinical_pathway');
    }
};
