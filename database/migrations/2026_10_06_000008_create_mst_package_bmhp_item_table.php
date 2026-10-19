<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Isi paket BMHP — satu baris per BMHP satuan di dalam sebuah paket beserta
     * jumlahnya. Satuan & harga tidak disalin ke sini; keduanya selalu dibaca
     * dari `mst_bmhp`.
     *
     * Tidak ada indeks unik (paket, BMHP): barisnya soft delete, jadi BMHP yang
     * pernah dikeluarkan dari paket harus bisa dimasukkan lagi. Larangan BMHP
     * kembar dalam satu paket dijaga validasi di controller.
     */
    public function up(): void
    {
        Schema::create('mst_package_bmhp_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_bmhp_id')->constrained('mst_package_bmhp')->cascadeOnDelete();
            $table->foreignId('bmhp_id')->constrained('mst_bmhp');
            $table->decimal('qty', 12, 2)->default(1);
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
        Schema::dropIfExists('mst_package_bmhp_item');
    }
};
