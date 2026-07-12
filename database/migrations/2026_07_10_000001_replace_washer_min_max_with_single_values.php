<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sederhanakan parameter mesin washer: ganti rentang min–max suhu & durasi
     * menjadi satu nilai standar masing-masing (`temperature`, `duration_minutes`).
     * Nilai standar diperlakukan sebagai batas minimum — hasil pencucian di bawahnya
     * ditandai gagal/alert. Data lama dipindahkan dari batas bawah (fallback batas atas).
     */
    public function up(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->decimal('temperature', 5, 2)->nullable()->after('location');
            $table->unsignedInteger('duration_minutes')->nullable()->after('temperature');
        });

        // Pindahkan nilai lama: pakai batas bawah, fallback batas atas.
        DB::table('washer_machines')->update([
            'temperature' => DB::raw('COALESCE(min_temperature, max_temperature)'),
            'duration_minutes' => DB::raw('COALESCE(min_duration_minutes, max_duration_minutes)'),
        ]);

        Schema::table('washer_machines', function (Blueprint $table) {
            $table->dropColumn([
                'min_temperature',
                'max_temperature',
                'min_duration_minutes',
                'max_duration_minutes',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->decimal('min_temperature', 5, 2)->nullable()->after('location');
            $table->decimal('max_temperature', 5, 2)->nullable()->after('min_temperature');
            $table->unsignedInteger('min_duration_minutes')->nullable()->after('max_temperature');
            $table->unsignedInteger('max_duration_minutes')->nullable()->after('min_duration_minutes');
        });

        // Kembalikan nilai tunggal ke batas bawah rentang.
        DB::table('washer_machines')->update([
            'min_temperature' => DB::raw('temperature'),
            'min_duration_minutes' => DB::raw('duration_minutes'),
        ]);

        Schema::table('washer_machines', function (Blueprint $table) {
            $table->dropColumn(['temperature', 'duration_minutes']);
        });
    }
};
