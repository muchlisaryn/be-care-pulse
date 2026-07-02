<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verifikasi clinical pathway: dokter penanggung jawab, perawat penanggung
     * jawab, dan pelaksana verifikasi (tanda clinical pathway selesai). Tiap
     * verifikasi menyimpan username pemverifikasi (*_by) + waktunya (*_at).
     */
    public function up(): void
    {
        Schema::table('clinical_pathway_assessments', function (Blueprint $table) {
            $table->string('doctor_verified_by')->nullable()->after('is_referral');
            $table->timestamp('doctor_verified_at')->nullable()->after('doctor_verified_by');
            $table->string('nurse_verified_by')->nullable()->after('doctor_verified_at');
            $table->timestamp('nurse_verified_at')->nullable()->after('nurse_verified_by');
            $table->string('executor_verified_by')->nullable()->after('nurse_verified_at');
            $table->timestamp('executor_verified_at')->nullable()->after('executor_verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_pathway_assessments', function (Blueprint $table) {
            $table->dropColumn([
                'doctor_verified_by', 'doctor_verified_at',
                'nurse_verified_by', 'nurse_verified_at',
                'executor_verified_by', 'executor_verified_at',
            ]);
        });
    }
};
