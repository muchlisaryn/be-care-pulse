<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sterilizations', function (Blueprint $table) {
            // Indikator biologis dipisah jadi dua: pembanding (kontrol) & uji.
            // Nilai: "Negatif" / "Positif".
            $table->string('bio_indicator_control')->nullable()->after('biological_indicator');
            $table->string('bio_indicator_test')->nullable()->after('bio_indicator_control');
        });
    }

    public function down(): void
    {
        Schema::table('sterilizations', function (Blueprint $table) {
            $table->dropColumn(['bio_indicator_control', 'bio_indicator_test']);
        });
    }
};
