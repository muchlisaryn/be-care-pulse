<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `packaging_bmhp.prefix` dibuat varchar(8) saat tabelnya pertama kali
     * dimigrasikan, lalu definisinya di migration lama diubah jadi 64 tanpa
     * migration baru — database yang sudah terlanjur migrate tetap varchar(8).
     *
     * Akibatnya prefix `BMHP-{kode master}-` (mis. `BMHP-BMHP-001-`) gagal
     * disimpan: "Data too long for column 'prefix'". Samakan lebarnya dengan
     * definisi terkini (64) agar muat kode master sampai 50 karakter.
     */
    public function up(): void
    {
        if (! Schema::hasTable('packaging_bmhp')) {
            return;
        }

        Schema::table('packaging_bmhp', function (Blueprint $table) {
            $table->string('prefix', 64)->default('PKG')->change();
        });
    }

    public function down(): void
    {
        // Sengaja kosong — data prefix yang sudah panjang akan terpotong bila
        // kolomnya dikembalikan ke varchar(8).
    }
};
