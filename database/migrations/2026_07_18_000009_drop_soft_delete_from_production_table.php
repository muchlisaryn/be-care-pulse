<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch produksi tidak pernah di-soft-delete: satu-satunya penghapusan adalah
     * pembatalan di tahap cleaning, dan itu memang hard delete agar slot nomor
     * PRD-nya kosong kembali (lihat CleaningController::cancel). Kolom soft delete
     * karenanya selalu null.
     *
     * Konsekuensi: model Production melepas trait HasAuditColumns (global scope
     * `deleted_by IS NULL` tidak lagi punya kolom). Pengisian otomatis
     * created_by/updated_by dipindah ke model event di Production sendiri.
     * Melengkapi penghapusan status (000005), source/reference_code (000007),
     * dan started/completed (000008).
     */
    public function up(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->dropColumn(['deleted_by', 'deleted_user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->string('deleted_by')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }
};
