<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai pengisian ceklis per poin untuk satu asesmen.
     * Satu baris = satu poin pada satu asesmen (unik), menyimpan hari mana saja
     * yang diceklis (checked_hari) + keterangan poin.
     */
    public function up(): void
    {
        Schema::create('asesmen_point_clinical_pathway', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asesmen_id')->constrained('asesmen_clinical_pathway')->cascadeOnDelete();
            $table->foreignId('point_id')->constrained('point_clinical_pathway')->cascadeOnDelete();
            // Hari ke berapa saja poin ini diceklis (array angka, subset 1..maksimal_hari).
            $table->json('checked_hari')->nullable();
            $table->text('keterangan')->nullable();

            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            // Satu poin hanya punya satu baris nilai per asesmen.
            $table->unique(['asesmen_id', 'point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asesmen_point_clinical_pathway');
    }
};
