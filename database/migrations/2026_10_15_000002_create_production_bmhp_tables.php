<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel-tabel alur Produksi BMHP (Bahan Medis Habis Pakai):
     *  1. `production_bmhp`: Batch produksi baru BMHP
     *  2. `production_bmhp_item`: Detail item satuan atau paket yang diproduksi
     *  3. `packaging_bmhp`: Batch inspeksi & pengemasan BMHP
     *  4. `packaging_bmhp_item`: Label kemasan & barcode per bungkus
     *  5. `sterilization_bmhp`: Siklus sterilisasi mesin untuk BMHP
     *  6. `sterilization_bmhp_items`: Hubungan kemasan BMHP ke siklus sterilisasi
     */
    public function up(): void
    {
        if (! Schema::hasTable('production_bmhp')) {
            Schema::create('production_bmhp', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->text('note')->nullable();
                $table->string('status')->default('diproses');
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->unsignedBigInteger('deleted_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_bmhp_item')) {
            Schema::create('production_bmhp_item', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_bmhp_id')->constrained('production_bmhp')->cascadeOnDelete();
                $table->string('source')->default('satuan'); // 'satuan' | 'paket'
                $table->foreignId('bmhp_id')->nullable()->constrained('mst_bmhp')->nullOnDelete();
                $table->foreignId('package_bmhp_id')->nullable()->constrained('mst_package_bmhp')->nullOnDelete();
                $table->string('name');
                $table->string('package_name')->nullable();
                $table->unsignedInteger('package_no')->nullable();
                $table->decimal('quantity', 12, 2)->default(1);
                $table->string('unit_name')->nullable();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->unsignedBigInteger('deleted_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('packaging_bmhp')) {
            Schema::create('packaging_bmhp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_bmhp_id')->constrained('production_bmhp')->cascadeOnDelete();
                $table->string('prefix', 8)->default('PKG');
                $table->string('code')->unique();
                $table->foreignId('packaging_type_id')->nullable()->constrained('packaging_types')->nullOnDelete();
                $table->string('chemical_indicator')->nullable();
                $table->string('operator')->nullable();
                $table->timestamp('packaged_at')->nullable();
                $table->date('expiry_date')->nullable();
                $table->text('note')->nullable();
                $table->string('status')->default('diproses'); // diproses, selesai, sterilisasi
                $table->string('started_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->string('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
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

        if (! Schema::hasTable('packaging_bmhp_item')) {
            Schema::create('packaging_bmhp_item', function (Blueprint $table) {
                $table->id();
                $table->foreignId('packaging_bmhp_id')->constrained('packaging_bmhp')->cascadeOnDelete();
                $table->foreignId('production_bmhp_item_id')->constrained('production_bmhp_item')->cascadeOnDelete();
                $table->string('barcode_no')->nullable()->index();
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

        if (! Schema::hasTable('sterilization_bmhp')) {
            Schema::create('sterilization_bmhp', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->foreignId('machine_id')->nullable()->constrained('sterilizer_machines')->nullOnDelete();
                $table->string('machine_name')->nullable();
                $table->string('method')->default('uap');
                $table->unsignedInteger('cycle_number')->nullable();
                $table->decimal('temperature', 8, 2)->nullable();
                $table->unsignedInteger('duration_minutes')->nullable();
                $table->string('operator')->nullable();
                $table->string('bio_indicator_lot')->nullable();
                $table->string('chemical_indicator_lot')->nullable();
                $table->string('status')->default('diproses'); // diproses, selesai, gagal
                $table->timestamp('sterilized_at')->nullable();
                $table->date('expiry_date')->nullable();
                $table->text('note')->nullable();
                $table->string('started_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->string('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->unsignedBigInteger('deleted_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sterilization_bmhp_items')) {
            Schema::create('sterilization_bmhp_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sterilization_bmhp_id')->constrained('sterilization_bmhp')->cascadeOnDelete();
                $table->foreignId('packaging_bmhp_id')->constrained('packaging_bmhp')->cascadeOnDelete();
                $table->string('packaging_barcode')->nullable()->index();
                $table->string('result')->nullable(); // steril, gagal
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
        Schema::dropIfExists('sterilization_bmhp_items');
        Schema::dropIfExists('sterilization_bmhp');
        Schema::dropIfExists('packaging_bmhp_item');
        Schema::dropIfExists('packaging_bmhp');
        Schema::dropIfExists('production_bmhp_item');
        Schema::dropIfExists('production_bmhp');
    }
};
