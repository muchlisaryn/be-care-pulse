<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai `order.order_type` mengikuti nama jenis order di layar: `instrumen`
     * (dulu `cssd`) dan `bmhp`.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('order', 'order_type')) {
            return;
        }

        Schema::table('order', function (Blueprint $table) {
            $table->string('order_type', 32)->default('instrumen')->change();
        });

        DB::table('order')->where('order_type', 'cssd')->update(['order_type' => 'instrumen']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order', 'order_type')) {
            return;
        }

        DB::table('order')->where('order_type', 'instrumen')->update(['order_type' => 'cssd']);

        Schema::table('order', function (Blueprint $table) {
            $table->string('order_type', 32)->default('cssd')->change();
        });
    }
};
