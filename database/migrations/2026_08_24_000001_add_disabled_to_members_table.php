<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda `disabled` pada anggota: `true` = baris ini tidak boleh muncul lagi di
 * mana pun dalam aplikasi.
 *
 * BEDANYA dengan `disabled` di tabel transaksi. Di sana nilainya murni turunan
 * `deleted_by` (lihat `MarksDisabledWhenDeleted`). Di sini ada SATU sebab kedua:
 * anggota yang seluruh transaksinya sudah dipindahkan ke anggota lain lewat
 * Gabung Anggota. Anggota itu bukan dihapus — jejaknya wajib tetap ada agar
 * riwayat penggabungan bisa dibaca dan dibalik — tapi ia sudah tidak boleh
 * dipakai lagi.
 *
 * Karena itu ditambahkan dua kolom pendamping yang menyimpan SEBABNYA:
 *
 *  - `merged_at`            — kapan digabungkan; NULL = tidak pernah;
 *  - `merged_to_member_id`  — ke anggota mana transaksinya berpindah.
 *
 * `disabled` sendiri tetap DITURUNKAN, tidak pernah diisi tangan:
 * `disabled = (deleted_by IS NOT NULL) OR (merged_at IS NOT NULL)`, dipasang
 * model `Member` lewat event `saving`. Dua kolom yang menjawab pertanyaan sama
 * hanya berguna kalau mustahil berselisih.
 *
 * `merged_to_member_id` sengaja BUKAN foreign key: ia menunjuk baris `members`
 * yang sama, dan constraint ke tabel sendiri membuat penghapusan anggota tujuan
 * gagal dengan galat SQL mentah alih-alih pesan yang bisa dibaca petugas.
 * Keutuhannya dijaga `MemberMerge`, yang menyimpan pasangan itu berikut seluruh
 * rincian perpindahannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('members', 'disabled')) {
            Schema::table('members', function (Blueprint $table) {
                $table->boolean('disabled')->default(false)->after('deleted_user_id');
                $table->timestamp('merged_at')->nullable()->after('disabled');
                $table->unsignedBigInteger('merged_to_member_id')->nullable()->after('merged_at');

                // Global scope `enabled` menyaring dengan kolom ini di SETIAP
                // query anggota — tanpa index, seluruh daftar & pencarian
                // anggota berubah jadi pemindaian tabel penuh.
                $table->index('disabled');
            });
        }

        // Baris lama diselaraskan dengan keadaan hapusnya. Tanpa ini anggota yang
        // sudah terhapus tetap berbunyi `disabled = false` dan kedua kolom itu
        // langsung bertentangan sejak hari pertama.
        DB::table('members')->update([
            'disabled' => DB::raw('CASE WHEN deleted_by IS NULL THEN 0 ELSE 1 END'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('members', 'disabled')) {
            return;
        }

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['disabled']);
            $table->dropColumn(['disabled', 'merged_at', 'merged_to_member_id']);
        });
    }
};
