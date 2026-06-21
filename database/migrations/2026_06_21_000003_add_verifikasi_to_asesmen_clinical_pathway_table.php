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
        Schema::table('asesmen_clinical_pathway', function (Blueprint $table) {
            $table->string('verifikasi_dokter_by')->nullable()->after('rujukan');
            $table->timestamp('verifikasi_dokter_at')->nullable()->after('verifikasi_dokter_by');
            $table->string('verifikasi_perawat_by')->nullable()->after('verifikasi_dokter_at');
            $table->timestamp('verifikasi_perawat_at')->nullable()->after('verifikasi_perawat_by');
            $table->string('verifikasi_pelaksana_by')->nullable()->after('verifikasi_perawat_at');
            $table->timestamp('verifikasi_pelaksana_at')->nullable()->after('verifikasi_pelaksana_by');
        });
    }

    public function down(): void
    {
        Schema::table('asesmen_clinical_pathway', function (Blueprint $table) {
            $table->dropColumn([
                'verifikasi_dokter_by', 'verifikasi_dokter_at',
                'verifikasi_perawat_by', 'verifikasi_perawat_at',
                'verifikasi_pelaksana_by', 'verifikasi_pelaksana_at',
            ]);
        });
    }
};
