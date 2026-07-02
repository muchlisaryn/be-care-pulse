<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pencatatan varian clinical pathway — catatan penyimpangan (varian) yang
     * terjadi selama perawatan pasien, beserta alasan & paraf (username pengisi).
     * Satu asesmen bisa punya banyak catatan varian.
     */
    public function up(): void
    {
        Schema::create('clinical_pathway_variances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('clinical_pathway_assessments')->cascadeOnDelete();

            $table->dateTime('occurred_at');     // tanggal & waktu varian terjadi
            $table->text('variance');            // varian yang terjadi
            $table->text('reason')->nullable();  // alasan varian terjadi
            $table->string('initials');          // paraf: username user yang login saat mencatat

            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_pathway_variances');
    }
};
