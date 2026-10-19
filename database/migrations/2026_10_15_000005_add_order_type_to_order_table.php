<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom order_type ke tabel order, serta bmhp_id, package_bmhp_id, dan item_name ke order_request_item.
     */
    public function up(): void
    {
        if (Schema::hasTable('order') && ! Schema::hasColumn('order', 'order_type')) {
            Schema::table('order', function (Blueprint $table) {
                $table->string('order_type', 32)->default('cssd')->after('code_transaction');
            });
        }

        if (Schema::hasTable('order_request_item')) {
            Schema::table('order_request_item', function (Blueprint $table) {
                if (! Schema::hasColumn('order_request_item', 'bmhp_id')) {
                    $table->foreignId('bmhp_id')->nullable()->after('instrument_id')->constrained('mst_bmhp')->nullOnDelete();
                }
                if (! Schema::hasColumn('order_request_item', 'package_bmhp_id')) {
                    $table->foreignId('package_bmhp_id')->nullable()->after('instrument_catalog_id')->constrained('mst_package_bmhp')->nullOnDelete();
                }
                if (! Schema::hasColumn('order_request_item', 'item_name')) {
                    $table->string('item_name')->nullable()->after('package_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_request_item')) {
            Schema::table('order_request_item', function (Blueprint $table) {
                if (Schema::hasColumn('order_request_item', 'bmhp_id')) {
                    $table->dropForeign(['bmhp_id']);
                    $table->dropColumn('bmhp_id');
                }
                if (Schema::hasColumn('order_request_item', 'package_bmhp_id')) {
                    $table->dropForeign(['package_bmhp_id']);
                    $table->dropColumn('package_bmhp_id');
                }
                if (Schema::hasColumn('order_request_item', 'item_name')) {
                    $table->dropColumn('item_name');
                }
            });
        }

        if (Schema::hasTable('order') && Schema::hasColumn('order', 'order_type')) {
            Schema::table('order', function (Blueprint $table) {
                $table->dropColumn('order_type');
            });
        }
    }
};
