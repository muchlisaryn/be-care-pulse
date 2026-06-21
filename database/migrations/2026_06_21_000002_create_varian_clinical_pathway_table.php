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
        Schema::create('varian_clinical_pathway', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asesmen_id')->constrained('asesmen_clinical_pathway')->cascadeOnDelete();

            $table->dateTime('tanggal_waktu');       // tanggal & waktu varian terjadi
            $table->text('varian');                  // varian yang terjadi
            $table->text('alasan')->nullable();      // alasan varian terjadi
            $table->string('paraf');                 // username user yang login saat mencatat

            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('varian_clinical_pathway');
    }
};
