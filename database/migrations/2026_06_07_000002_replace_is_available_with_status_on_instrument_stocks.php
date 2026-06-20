<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_stocks', function (Blueprint $table) {
            $table->string('status')->default('tersedia')->after('condition_id');
        });

        // Migrasi data lama: boolean -> status
        DB::table('instrument_stocks')->where('is_available', true)->update(['status' => 'tersedia']);
        DB::table('instrument_stocks')->where('is_available', false)->update(['status' => 'dipinjam']);

        Schema::table('instrument_stocks', function (Blueprint $table) {
            $table->dropColumn('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('instrument_stocks', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('condition_id');
        });

        DB::table('instrument_stocks')->where('status', 'tersedia')->update(['is_available' => true]);
        DB::table('instrument_stocks')->where('status', '!=', 'tersedia')->update(['is_available' => false]);

        Schema::table('instrument_stocks', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
