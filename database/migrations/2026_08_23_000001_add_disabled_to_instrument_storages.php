<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda VOID untuk baris gudang steril — dipakai fitur "Packaging Ulang" pada
 * halaman Alat Kedaluwarsa Steril.
 *
 * Saat unit kedaluwarsa ditarik dari rak untuk dikemas ulang, barisnya TIDAK
 * dihapus (riwayat rak, batch steril, dan nomor label harus tetap terbaca) —
 * cukup ditandai di sini. Konvensinya sama dengan `packaging`, `packaging_item`,
 * `washing_item`, dan `sterilization_item` yang sudah lebih dulu punya pasangan
 * kolom `disabled` + `disabled_at`.
 *
 * Kenapa `removed_at` saja tidak cukup — dua sebab, keduanya nyata:
 *
 *  1. `InstrumentStock::scopeAvailableStock()` menyimpulkan "siklus produksi unit
 *     sudah tuntas" dari ADA/TIDAKNYA baris gudang untuk `production_item`
 *     terakhirnya. Barisnya tetap ada setelah `removed_at` diisi, jadi unit yang
 *     baru dikirim ke packaging akan langsung terhitung Tersedia lagi di Master
 *     dan bisa tertarik ke batch produksi lain padahal fisiknya sedang diproses.
 *  2. `InstrumentStock::computeStages()` membaca baris gudang TERBARU unit untuk
 *     menentukan badge tahapnya. Tanpa penanda ini, baris yang sudah di-void
 *     tetap dianggap menggambarkan keadaan unit dan badge-nya berbunyi
 *     "Kedaluwarsa" padahal barangnya sedang antre disterilkan ulang.
 *
 * `disabled_at` juga jadi syarat baru di `InstrumentStorage::sterilePool()`,
 * sehingga unit yang barisnya di-void tidak bisa dipinjam lewat jalur mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('instrument_storages', 'disabled')) {
            return;
        }

        Schema::table('instrument_storages', function (Blueprint $table) {
            // Ditaruh setelah `removed_at` supaya berdekatan dengan kolom jejak
            // "baris ini sudah tidak di rak" yang lain.
            $table->boolean('disabled')->default(false)->after('removed_at');
            $table->timestamp('disabled_at')->nullable()->after('disabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('instrument_storages', 'disabled')) {
            return;
        }

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropColumn(['disabled', 'disabled_at']);
        });
    }
};
