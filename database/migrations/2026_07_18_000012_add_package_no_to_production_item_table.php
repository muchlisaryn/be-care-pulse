<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda SET KE-BERAPA dalam satu batch. Selama ini unit paket hanya dibedakan
     * lewat `package_name`, sehingga 2 set "HECTING SET" dalam satu batch melebur
     * jadi satu kelompok berisi 10 unit — bukan 2 kelompok berisi 5 unit. Nomor ini
     * berurut per batch (1, 2, 3, ...) lintas nama paket, jadi tiap set fisik punya
     * identitas sendiri.
     *
     * Null pada baris `satuan`, dan pada baris paket lama yang dibuat sebelum kolom
     * ini ada — komposisi set-nya tidak bisa direkonstruksi, jadi sengaja dibiarkan
     * null dan tetap dikelompokkan per nama paket seperti perilaku lama.
     */
    public function up(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->unsignedInteger('package_no')->nullable()->after('package_name');
        });
    }

    public function down(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->dropColumn('package_no');
        });
    }
};
