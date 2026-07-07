<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengirim & penerima distribusi kini free text (bukan lagi FK ke users).
     * Menambah kolom string `sender` / `receiver`, mengisi ulang dari nama user
     * relasi lama, lalu membuat `receiver_id` nullable karena tak dipakai lagi.
     */
    public function up(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->string('sender')->nullable()->after('sender_id');
            $table->string('receiver')->nullable()->after('receiver_id');
        });

        // Backfill teks dari nama user relasi lama agar data lama tetap tampil.
        DB::statement('UPDATE distributions SET sender = (SELECT name FROM users WHERE users.id = distributions.sender_id) WHERE sender IS NULL AND sender_id IS NOT NULL');
        DB::statement('UPDATE distributions SET receiver = (SELECT name FROM users WHERE users.id = distributions.receiver_id) WHERE receiver IS NULL AND receiver_id IS NOT NULL');

        // Penerima kini free text → receiver_id tak lagi wajib.
        Schema::table('distributions', function (Blueprint $table) {
            $table->unsignedBigInteger('receiver_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->dropColumn(['sender', 'receiver']);
        });
    }
};
