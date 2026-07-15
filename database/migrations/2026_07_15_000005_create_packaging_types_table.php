<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master jenis kemasan (linen, pouch, container, ...) pada tahap Packaging.
     * Kode auto PKS-NNN. `shelf_life_days` = masa simpan steril jenis kemasan ini;
     * pilihan operator saat "Selesai Pengemasan" menentukan tgl kedaluwarsa batch.
     * Menggantikan konstanta Packaging::PACKAGING_TYPES agar bisa dikelola admin.
     */
    public function up(): void
    {
        Schema::create('packaging_types', function (Blueprint $table) {
            $table->id();
            // Kode jenis kemasan (auto PKS-NNN).
            $table->string('code')->unique();
            $table->string('name');
            // Masa simpan steril (hari) — menentukan tgl kedaluwarsa batch.
            $table->unsignedInteger('shelf_life_days');
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
        Schema::dropIfExists('packaging_types');
    }
};
