<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak mulai/selesai tidak bermakna di tahap Produksi: batch dibuat & unit
     * dikunci dalam satu aksi, sehingga started_at = completed_at = created_at dan
     * started_by = completed_by = created_by. Kolom audit `created_at`/`created_by`
     * sudah mencatat hal yang sama. Menyusul penghapusan `status` (000005) dan
     * `source`/`reference_code` (000007).
     */
    public function up(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->dropColumn(['started_by', 'started_at', 'completed_by', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('production', function (Blueprint $table) {
            $table->string('started_by')->nullable()->after('note');
            $table->timestamp('started_at')->nullable()->after('started_by');
            $table->string('completed_by')->nullable()->after('started_at');
            $table->timestamp('completed_at')->nullable()->after('completed_by');
        });
    }
};
