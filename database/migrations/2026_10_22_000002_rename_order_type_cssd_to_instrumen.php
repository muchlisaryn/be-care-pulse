<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai `order.order_type` untuk order instrumen kini `instrumen` (dulu `cssd`).
     * Migrasi pembuat kolomnya sudah memakai `instrumen`; ini hanya untuk database
     * yang terlanjur memakai `cssd`.
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
        // Sengaja kosong — nilai `cssd` sudah tidak dipakai.
    }
};
