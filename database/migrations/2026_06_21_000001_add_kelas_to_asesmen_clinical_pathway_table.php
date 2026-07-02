<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom ward_class (kelas perawatan pasien) pada asesmen clinical
     * pathway. Ditaruh setelah room_id karena saling berkaitan (ruang & kelas rawat).
     */
    public function up(): void
    {
        Schema::table('clinical_pathway_assessments', function (Blueprint $table) {
            $table->string('ward_class')->nullable()->after('room_id');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_pathway_assessments', function (Blueprint $table) {
            $table->dropColumn('ward_class');
        });
    }
};
