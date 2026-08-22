<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persentase jasa ketua kelompok, mis. `10.00` untuk 10%.
 *
 * Yang menentukan dua kolom rupiah di sebelahnya: `group_leader_deduction` dan
 * `group_leader_fee` sama-sama diisi persentase ini dikali total rincian. Ketua
 * kelompok menahan komisinya dari uang yang ia kumpulkan, jadi angkanya muncul
 * sebagai potongan sekaligus sebagai jasa — keduanya saling menghapus di
 * `balance`, dan yang disetorkan tetap total dikurangi potongan anggota.
 *
 * Persentasenya ikut disimpan, bukan cuma nominalnya: saat kuitansi lama dibuka
 * kembali, isian persen di form harus tampil apa adanya (10), bukan hasil hitung
 * mundur `rupiah ÷ total` yang bisa meleset karena pembulatan — dan tidak bisa
 * dihitung sama sekali kalau totalnya nol.
 *
 * decimal(5,2): cukup untuk 0–100 dengan dua angka di belakang koma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_headers', 'group_leader_fee_percent')) {
                $table->decimal('group_leader_fee_percent', 5, 2)
                    ->default(0)
                    ->after('group_leader_deduction');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_headers', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_headers', 'group_leader_fee_percent')) {
                $table->dropColumn('group_leader_fee_percent');
            }
        });
    }
};
