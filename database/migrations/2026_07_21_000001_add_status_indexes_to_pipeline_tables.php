<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk mempercepat DUA query daftar pipeline di SterilizationPipelineController::index:
 *
 *  - PKG siap-steril: `packaging WHERE status = 'selesai' AND disabled = 0`
 *    → index komposit (status, disabled).
 *  - Batch STR: `sterilizations WHERE status IN (...) AND order_id IS NULL`
 *    → index (status); `order_id` sudah ter-index lewat foreign key.
 *
 * Kolom RELASI (instrument_stock_id, washing_code, sterilization_id, production_code,
 * barcode_no, packaging_barcode) sudah ter-index sejak awal — jadi lookup relasinya
 * memang sudah cepat; migration ini hanya melengkapi filter status/disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->index(['status', 'disabled'], 'packaging_status_disabled_index');
        });

        Schema::table('sterilizations', function (Blueprint $table) {
            $table->index('status', 'sterilizations_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropIndex('packaging_status_disabled_index');
        });

        Schema::table('sterilizations', function (Blueprint $table) {
            $table->dropIndex('sterilizations_status_index');
        });
    }
};
