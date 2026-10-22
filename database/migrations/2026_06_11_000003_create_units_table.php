<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master satuan (pcs, box, botol, ...). Dikelola admin lewat /master/satuan
     * dan disediakan sebagai pilihan dropdown lewat endpoint `units/options`.
     */
    public function up(): void
    {
        // Database lama sudah menjalankan migrasi ini dengan nama
        // 2026_10_06_000001_create_units_table. Jangan buat ulang tabelnya.
        if (Schema::hasTable('units')) {
            return;
        }

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            // Kolom audit standar (lihat HasAuditColumns).
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
