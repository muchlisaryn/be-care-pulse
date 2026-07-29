<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baris gudang steril menunjuk langsung ke baris batch produksi (production_item)
 * menggantikan instrument_stock_id + salinan `source`/`package_name`.
 *
 * production_item sudah menyimpan identitas lengkap unit saat dikunci ke batch
 * (instrument_stock_id, kode_instrumen, name, source, package_name), jadi kolom
 * denormalisasi di gudang jadi duplikat yang bisa basi. Setelah ini `name` &
 * `source` dibaca lewat relasi production_item.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pasangan production_item tiap baris gudang = baris produksi TERBARU unit
        // itu (id terbesar) — aturan yang sama dengan "batch terbaru menimpa yang
        // lama" yang sudah dipakai aplikasi saat membaca nama/kode unit.
        $unmatched = DB::table('instrument_storages as s')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('production_item as pi')
                ->whereColumn('pi.instrument_stock_id', 's.instrument_stock_id')
                ->whereNull('pi.deleted_by'))
            ->pluck('s.id');

        // Gagal di depan (sebelum skema disentuh) supaya tidak ada baris gudang yang
        // kehilangan asal-usulnya secara diam-diam.
        if ($unmatched->isNotEmpty()) {
            throw new RuntimeException(
                'Migration dibatalkan: baris instrument_storages berikut tidak punya pasangan production_item — id '
                .$unmatched->implode(', ')
                .'. Perbaiki atau hapus baris tersebut lalu jalankan ulang.'
            );
        }

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->foreignId('production_item_id')->nullable()->after('sterilization_id');
        });

        DB::statement(<<<'SQL'
            UPDATE instrument_storages s
            JOIN (
                SELECT instrument_stock_id, MAX(id) AS production_item_id
                FROM production_item
                WHERE deleted_by IS NULL
                GROUP BY instrument_stock_id
            ) pi ON pi.instrument_stock_id = s.instrument_stock_id
            SET s.production_item_id = pi.production_item_id
        SQL);

        // FK dulu, baru index: MySQL menolak menghapus index yang masih menopang
        // sebuah foreign key (error 1553).
        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropForeign(['instrument_stock_id']);
        });

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropIndex(['instrument_stock_id', 'status']);
            $table->dropColumn(['instrument_stock_id', 'source', 'package_name']);
        });

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->unsignedBigInteger('production_item_id')->nullable(false)->change();
            $table->foreign('production_item_id')->references('id')->on('production_item')->restrictOnDelete();
            $table->index(['production_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->foreignId('instrument_stock_id')->nullable()->after('sterilization_id');
            $table->string('source')->default('satuan')->after('instrument_stock_id');
            $table->string('package_name')->nullable()->after('source');
        });

        DB::statement(<<<'SQL'
            UPDATE instrument_storages s
            JOIN production_item pi ON pi.id = s.production_item_id
            SET s.instrument_stock_id = pi.instrument_stock_id,
                s.source = pi.source,
                s.package_name = pi.package_name
        SQL);

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->dropIndex(['production_item_id', 'status']);
            $table->dropForeign(['production_item_id']);
            $table->dropColumn('production_item_id');
        });

        Schema::table('instrument_storages', function (Blueprint $table) {
            $table->unsignedBigInteger('instrument_stock_id')->nullable(false)->change();
            $table->foreign('instrument_stock_id')->references('id')->on('instrument_stocks')->restrictOnDelete();
            $table->index(['instrument_stock_id', 'status']);
        });
    }
};
