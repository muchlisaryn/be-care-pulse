<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill kolom `disabled` / `disabled_at` di tabel `packaging`.
 *
 * Kolom ini didefinisikan di migration create (`create_cssd_pipeline_tables`)
 * dan dipakai luas oleh kode aplikasi (PackagingController,
 * SterilizationPipelineController, model Packaging), tetapi pada sebagian
 * database — yang sudah menjalankan migration create sebelum kolom ini
 * ditambahkan ke sumbernya — kolomnya tidak pernah terbentuk.
 *
 * Migration ini menambahkannya secara idempotent (guard `hasColumn`) sehingga
 * aman dijalankan baik di database lama maupun fresh. Sengaja diberi timestamp
 * lebih awal dari `2026_07_21_000001_add_status_indexes_to_pipeline_tables`
 * agar berjalan lebih dulu (index memakai kolom `disabled`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            if (! Schema::hasColumn('packaging', 'disabled')) {
                // Penanda PKG di-void (unitnya gagal steril & diproses ulang).
                $table->boolean('disabled')->default(false)->after('status');
            }

            if (! Schema::hasColumn('packaging', 'disabled_at')) {
                $table->timestamp('disabled_at')->nullable()->after('disabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            if (Schema::hasColumn('packaging', 'disabled_at')) {
                $table->dropColumn('disabled_at');
            }

            if (Schema::hasColumn('packaging', 'disabled')) {
                $table->dropColumn('disabled');
            }
        });
    }
};
