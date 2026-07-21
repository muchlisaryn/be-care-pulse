<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode/barcode mesin washer (WSH-NNN) dihapus — mesin kini dirujuk lewat id
     * (`washing.washer_machine_id`), bukan kode. Index unik ikut terbuang bersama
     * kolomnya.
     */
    public function up(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('washer_machines', function (Blueprint $table) {
            // Nilai lama tidak bisa dipulihkan — kolom dibuat ulang kosong.
            // Nullable supaya rollback tidak gagal pada tabel yang sudah berisi data.
            $table->string('code')->nullable()->unique()->after('id');
        });
    }
};
