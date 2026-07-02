<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kaitkan packaging ke batch sterilisasi yang menampungnya. Satu batch STR
     * bisa berisi banyak PKG (menggabungkan beberapa produksi satuan/paket yang
     * disterilkan bersamaan). `sterilization_id` null = belum masuk batch (siap-steril).
     */
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->foreignId('sterilization_id')->nullable()->after('washing_code')
                ->constrained('sterilizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sterilization_id');
        });
    }
};
