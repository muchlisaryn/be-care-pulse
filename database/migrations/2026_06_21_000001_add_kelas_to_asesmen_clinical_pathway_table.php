<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom kelas (kelas perawatan pasien) pada asesmen clinical pathway.
     * Ditaruh setelah ruang_id karena saling berkaitan (ruang & kelas rawat).
     */
    public function up(): void
    {
        Schema::table('asesmen_clinical_pathway', function (Blueprint $table) {
            $table->string('kelas')->nullable()->after('ruang_id');
        });
    }

    public function down(): void
    {
        Schema::table('asesmen_clinical_pathway', function (Blueprint $table) {
            $table->dropColumn('kelas');
        });
    }
};
