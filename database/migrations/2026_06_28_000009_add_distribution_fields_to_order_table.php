<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahap 6 — Distribution & Tracking. Saat alat steril didistribusikan & dipakai
     * ke pasien, tautkan ke Nomor Rekam Medis pasien (full traceability loop) +
     * catat petugas penerima & waktu distribusi.
     */
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->string('medical_record_no')->nullable()->after('returned_by');
            $table->string('patient_name')->nullable()->after('medical_record_no');
            $table->string('distributed_to')->nullable()->after('patient_name');
            $table->timestamp('distributed_at')->nullable()->after('distributed_to');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropColumn(['medical_record_no', 'patient_name', 'distributed_to', 'distributed_at']);
        });
    }
};
