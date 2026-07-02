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
        Schema::create('clinical_pathway_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('clinical_pathway_templates')->cascadeOnDelete();
            // Kategori (dari clinical_pathway_categories) yang menaungi poin ini.
            $table->foreignId('category_id')->constrained('clinical_pathway_categories')->cascadeOnDelete();
            // Parent poin (untuk sub-poin). Cascade dihapus di aplikasi (self-reference).
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('label');
            // Pengisi: dokter / perawat / farmasi / penunjang.
            $table->string('filled_by');
            // Hari berapa saja poin ini wajib diceklis (array angka hari, subset 1..max_days).
            $table->json('required_days')->nullable();
            // Urutan poin dalam parent/kategori (untuk penomoran 1.1, 1.1.1, dst).
            $table->integer('sort_order')->default(0);
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
        Schema::dropIfExists('clinical_pathway_points');
    }
};
