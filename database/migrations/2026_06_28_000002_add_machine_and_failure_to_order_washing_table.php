<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lengkapi catatan pencucian (Tahap 2 — Cleaning & Disinfection):
     * - washer_machine_id : mesin washer yang dipindai (master washer_machines)
     * - duration_minutes  : durasi pencucian terpisah dari waktu mulai
     * - failure_reason     : alasan bila pencucian ditandai gagal
     * - alert / alert_message : notifikasi kegagalan suhu/waktu di luar ambang mesin
     */
    public function up(): void
    {
        Schema::table('order_washing', function (Blueprint $table) {
            $table->foreignId('washer_machine_id')->nullable()->after('order_id')
                ->constrained('washer_machines')->nullOnDelete();
            $table->unsignedInteger('duration_minutes')->nullable()->after('washed_at');
            // Notifikasi kegagalan suhu/waktu mesin.
            $table->boolean('alert')->default(false)->after('detergent_type');
            $table->string('alert_message')->nullable()->after('alert');
            // Alasan bila pencucian ditandai "Gagal".
            $table->string('failure_reason')->nullable()->after('alert_message');
        });
    }

    public function down(): void
    {
        Schema::table('order_washing', function (Blueprint $table) {
            $table->dropConstrainedForeignId('washer_machine_id');
            $table->dropColumn(['duration_minutes', 'alert', 'alert_message', 'failure_reason']);
        });
    }
};
