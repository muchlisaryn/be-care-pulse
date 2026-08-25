<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda `disabled` pada kuitansi iuran & rinciannya: `true` begitu barisnya
 * dihapus, `false` selama belum.
 *
 * Nilainya TURUNAN dari `deleted_by`, diisi trait `MarksDisabledWhenDeleted`
 * lewat event `saving` — bukan kolom yang diisi tangan. Alasannya: `deleted_by`
 * sudah jadi penentu tunggal apakah sebuah baris terhapus (global scope `active`
 * membacanya), jadi kolom kedua yang menjawab pertanyaan yang sama hanya berguna
 * kalau mustahil berselisih dengannya.
 *
 * Jejak SIAPA & KAPAN yang menghapus tidak ditambahkan di sini karena sudah ada:
 * `HasAuditColumns::delete()` mengisi `deleted_at`, `deleted_by` (username), dan
 * `deleted_user_id` (id user) pada setiap penghapusan — ketiganya sudah menjadi
 * kolom baku tiap tabel domain di proyek ini.
 */
return new class extends Migration
{
    /** Kuitansi & rinciannya sama-sama perlu penanda ini. */
    private const TABEL = ['transaction_headers', 'transactions'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (Schema::hasColumn($tabel, 'disabled')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) {
                $table->boolean('disabled')->default(false)->after('deleted_user_id');
            });

            // Baris lama diselaraskan dengan keadaan hapusnya masing-masing.
            // Tanpa ini baris yang sudah terhapus tetap berbunyi `disabled = false`
            // dan kedua kolom itu langsung bertentangan sejak hari pertama.
            DB::table($tabel)->update([
                'disabled' => DB::raw('CASE WHEN deleted_by IS NULL THEN 0 ELSE 1 END'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (! Schema::hasColumn($tabel, 'disabled')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) {
                $table->dropColumn('disabled');
            });
        }
    }
};
