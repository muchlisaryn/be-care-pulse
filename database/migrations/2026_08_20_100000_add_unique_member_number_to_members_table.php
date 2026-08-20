<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jaring pengaman keunikan nomor anggota.
 *
 * Seluruh master Nafsul lain (`regions`, `cities`, `member_statuses`,
 * `group_leaders`, `rates`) sudah menjaga kolom `code` dengan index unik,
 * sedangkan `members.member_number` hanya diberi index biasa. Pengecekan
 * duplikat memang sudah ada di controller (impor maupun form), tetapi itu
 * pengecekan baca-lalu-tulis: dua permintaan yang berjalan bersamaan bisa
 * sama-sama lolos lalu sama-sama menyimpan.
 *
 * Index unik menutup celah itu di lapisan terakhir. Kolomnya nullable dan MySQL
 * mengizinkan NULL berulang pada index unik, jadi anggota yang nomornya belum
 * dibuat tetap bisa disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gagal lebih awal dengan pesan yang bisa ditindaklanjuti — lebih baik
        // daripada error MySQL mentah yang tidak menyebut nomor mana yang bentrok.
        $duplikat = DB::table('members')
            ->select('member_number', DB::raw('COUNT(*) AS jumlah'))
            ->whereNotNull('member_number')
            ->where('member_number', '!=', '')
            ->groupBy('member_number')
            ->having('jumlah', '>', 1)
            ->pluck('jumlah', 'member_number');

        if ($duplikat->isNotEmpty()) {
            $contoh = $duplikat->take(10)
                ->map(fn ($jumlah, $nomor) => "{$nomor} ({$jumlah}x)")
                ->implode(', ');

            throw new RuntimeException(
                'Tidak bisa memasang index unik: ada ' . $duplikat->count() .
                ' nomor anggota yang dobel. Rapikan dulu datanya. Contoh: ' . $contoh
            );
        }

        Schema::table('members', function (Blueprint $table) {
            $table->unique('member_number');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['member_number']);
        });
    }
};
