<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis order (`order.order_type`: `instrumen` / `bmhp`) + snapshot nama item
     * (`order_request_item.item_name`). Baris permintaan order BMHP punya tabel
     * sendiri — lihat 2026_10_22_000003_create_order_request_bmhp_table.
     */
    public function up(): void
    {
        if (Schema::hasTable('order') && ! Schema::hasColumn('order', 'order_type')) {
            Schema::table('order', function (Blueprint $table) {
                $table->string('order_type', 32)->default('instrumen')->after('code_transaction');
            });
        }

        if (Schema::hasTable('order_request_item') && ! Schema::hasColumn('order_request_item', 'item_name')) {
            Schema::table('order_request_item', function (Blueprint $table) {
                $table->string('item_name')->nullable()->after('package_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_request_item', 'item_name')) {
            Schema::table('order_request_item', function (Blueprint $table) {
                $table->dropColumn('item_name');
            });
        }

        if (Schema::hasColumn('order', 'order_type')) {
            Schema::table('order', function (Blueprint $table) {
                $table->dropColumn('order_type');
            });
        }
    }
};
