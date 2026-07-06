<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom `deleted_user_id` (id user yang menghapus) di semua tabel yang
     * memakai pola audit + soft delete (trait HasAuditColumns). Melengkapi
     * `deleted_by` yang menyimpan username: `deleted_user_id` menyimpan id user
     * sebagai snapshot jejak (bukan FK, agar tetap ada meski user dihapus).
     *
     * Tabel append-only (instrument_stock_logs, order_events, pipeline_events)
     * dikecualikan karena tidak melakukan soft delete.
     */
    private array $tables = [
        'users',
        'authorities',
        'title_menuses',
        'menus',
        'conditions',
        'instruments',
        'instrument_stocks',
        'rooms',
        'instrument_catalogs',
        'instrument_catalog_items',
        'bmhps',
        'washer_machines',
        'icd10',
        'order',
        'order_item',
        'order_request_item',
        'order_transfers',
        'order_transfer_items',
        'distributions',
        'distribution_items',
        'sterilizations',
        'sterilization_items',
        'instrument_storages',
        'production',
        'production_item',
        'washing',
        'packaging',
        'clinical_pathway_categories',
        'clinical_pathway_templates',
        'clinical_pathway_points',
        'clinical_pathway_assessments',
        'clinical_pathway_assessment_points',
        'clinical_pathway_variances',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_user_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('deleted_user_id')->nullable()->after('deleted_by')->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_user_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('deleted_user_id');
            });
        }
    }
};
