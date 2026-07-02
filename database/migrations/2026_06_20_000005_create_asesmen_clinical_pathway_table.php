<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asesmen clinical pathway — data pasien + diagnosa (mengacu ke satu
     * template/formulir). Pengisian ceklis per poin disimpan di tabel
     * clinical_pathway_assessment_points.
     */
    public function up(): void
    {
        Schema::create('clinical_pathway_assessments', function (Blueprint $table) {
            $table->id();
            // Formulir/template yang dipakai (menentukan diagnosa & maksimal hari).
            $table->foreignId('template_id')->constrained('clinical_pathway_templates')->cascadeOnDelete();

            // Identitas pasien. Wajib: medical_record_no & patient_name. Sisanya opsional.
            $table->string('medical_record_no'); // nomor rekam medis (wajib)
            $table->string('patient_name');
            $table->string('gender', 1)->nullable(); // L / P
            $table->date('birth_date')->nullable();

            // Data klinis.
            $table->string('admission_diagnosis')->nullable();
            $table->string('primary_disease')->nullable();
            $table->string('comorbidity')->nullable();
            $table->string('complication')->nullable();
            $table->string('procedure')->nullable();
            $table->decimal('weight', 5, 2)->nullable(); // berat badan (kg)
            $table->decimal('height', 5, 2)->nullable(); // tinggi badan (cm)

            // Perawatan.
            $table->dateTime('admitted_at')->nullable();
            $table->dateTime('discharged_at')->nullable();
            $table->integer('length_of_stay')->nullable();   // lama rawat (hari, diisi manual)
            $table->string('care_plan')->nullable();
            // Ruang rawat diambil dari master ruangan (rooms).
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->boolean('is_referral')->default(false);  // pasien rujukan: ya / tidak

            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_pathway_assessments');
    }
};
