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
        Schema::create('point_clinical_pathway', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('template_clinical_pathway')->cascadeOnDelete();
            // Kategori (dari categori_clinical_pathway) yang menaungi poin ini.
            $table->foreignId('categori_id')->constrained('categori_clinical_pathway')->cascadeOnDelete();
            // Parent poin (untuk sub-poin). Cascade dihapus di aplikasi (self-reference).
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('label');
            // Pengisi: dokter / perawat / farmasi / penunjang.
            $table->string('pengisi');
            // Hari berapa saja poin ini wajib diceklis (array angka hari, subset 1..maksimal_hari).
            $table->json('hari_wajib')->nullable();
            // Urutan poin dalam parent/kategori (untuk penomoran 1.1, 1.1.1, dst).
            $table->integer('urutan')->default(0);
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
        Schema::dropIfExists('point_clinical_pathway');
    }
};
