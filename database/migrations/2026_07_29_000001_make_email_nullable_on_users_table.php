<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email user jadi OPSIONAL. Banyak petugas CSSD tidak punya email kantor, dan
 * berkas import massal sering tidak memuat kolom itu — sebelumnya baris seperti
 * itu selalu gagal karena kolomnya NOT NULL.
 *
 * Indeks unique-nya sengaja DIPERTAHANKAN: MySQL mengizinkan banyak baris NULL
 * pada kolom unique, jadi email yang diisi tetap tidak boleh kembar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris ber-email NULL harus diisi lebih dulu agar kolomnya bisa dikembalikan
        // menjadi NOT NULL — pakai penanda unik supaya indeks unique tidak bentrok.
        DB::table('users')->whereNull('email')->update([
            'email' => DB::raw("CONCAT(username, '@no-email.local')"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
