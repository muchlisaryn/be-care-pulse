<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel penyimpanan BMHP steril di rak Gudang Steril BMHP.
     */
    public function up(): void
    {
        if (! Schema::hasTable('storage_bmhp')) {
            Schema::create('storage_bmhp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sterilization_bmhp_id')->constrained('sterilization_bmhp')->cascadeOnDelete();
                $table->foreignId('packaging_bmhp_id')->constrained('packaging_bmhp')->cascadeOnDelete();
                $table->foreignId('packaging_bmhp_item_id')->constrained('packaging_bmhp_item')->cascadeOnDelete();
                $table->foreignId('production_bmhp_item_id')->constrained('production_bmhp_item')->cascadeOnDelete();
                $table->string('barcode_no')->nullable()->index();
                // Rak penyimpanan (master `racks`). Kedaluwarsa tidak disimpan di
                // sini — dibaca dari kemasannya (packaging_bmhp.expiry_date); "masih
                // di rak" dibaca dari removed_at / disabled_at, tanpa kolom status.
                $table->foreignId('rack_id')->constrained('racks');
                $table->timestamp('stored_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->boolean('disabled')->default(false);
                $table->timestamp('disabled_at')->nullable();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->unsignedBigInteger('deleted_user_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_bmhp');
    }
};
