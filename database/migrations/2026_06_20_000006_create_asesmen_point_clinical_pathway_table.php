<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai pengisian ceklis per poin untuk satu asesmen.
     * Satu baris = satu poin pada satu asesmen (unik), menyimpan hari mana saja
     * yang diceklis (checked_days) + catatan poin (note).
     */
    public function up(): void
    {
        Schema::create('clinical_pathway_assessment_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('clinical_pathway_assessments')->cascadeOnDelete();
            $table->foreignId('point_id')->constrained('clinical_pathway_points')->cascadeOnDelete();
            // Hari ke berapa saja poin ini diceklis (array angka, subset 1..max_days).
            $table->json('checked_days')->nullable();
            $table->text('note')->nullable();

            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            // Satu poin hanya punya satu baris nilai per asesmen.
            $table->unique(['assessment_id', 'point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_pathway_assessment_points');
    }
};
