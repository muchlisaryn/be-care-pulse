<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master BMHP (Bahan Medis Habis Pakai / consumables).
     *
     * Gabungan create_bmhps_table + restructure_bmhps_into_mst_bmhp: langsung
     * bentuk akhirnya — kode diketik manual, satuan dari master `units`, dan
     * harga satuan (rupiah). Tanpa stok: stok BMHP ada di Gudang Steril BMHP.
     */
    public function up(): void
    {
        // Database lama sudah membentuk tabel ini lewat migrasi
        // 2026_10_06_000003_restructure_bmhps_into_mst_bmhp. Jangan buat ulang.
        if (Schema::hasTable('mst_bmhp')) {
            return;
        }

        Schema::create('mst_bmhp', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            // Kolom audit standar (lihat HasAuditColumns).
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_bmhp');
    }
};
