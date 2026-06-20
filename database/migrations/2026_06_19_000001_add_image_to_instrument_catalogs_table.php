<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_catalogs', function (Blueprint $table) {
            // Path relatif gambar set/paket (opsional), mis. uploads/instrument-catalogs/xxx.jpg
            $table->string('image')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('instrument_catalogs', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
