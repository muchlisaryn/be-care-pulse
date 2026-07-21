<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode packaging dipecah: `code` menyimpan bagian angka saja (ymd + urutan
     * harian, mis. `26050201`) dan `prefix` menyimpan jenis batchnya —
     * `PKG` (pengemasan normal) atau `RPK` (pengemasan ulang unit gagal steril).
     *
     * Karena tiap prefix punya deret nomor sendiri, index unik pindah dari `code`
     * saja ke gabungan (`prefix`, `code`) — tanpa itu PKG & RPK yang lahir di hari
     * sama akan tabrakan di angka yang sama.
     *
     * `reprocess_of` menunjuk id PKG lama yang di-void, supaya rantai re-proses
     * terlacak eksplisit (tidak perlu ditebak dari washing_code + urutan waktu).
     */
    public function up(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->string('prefix', 8)->default('PKG')->after('id');
            $table->unsignedBigInteger('reprocess_of')->nullable()->after('washing_code');
            $table->index('reprocess_of');
        });

        // Pisahkan kode lama: 'PKG-001' / 'PKG26071901' → prefix 'PKG' + sisa angka.
        foreach (DB::table('packaging')->select('id', 'code')->get() as $row) {
            $code = (string) $row->code;
            if (! str_starts_with($code, 'PKG')) {
                continue;
            }

            DB::table('packaging')->where('id', $row->id)->update([
                'prefix' => 'PKG',
                'code' => ltrim(substr($code, 3), '-'),
            ]);
        }

        Schema::table('packaging', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['prefix', 'code']);
        });
    }

    public function down(): void
    {
        // Gabungkan kembali prefix ke dalam `code` sebelum kolomnya dibuang.
        foreach (DB::table('packaging')->select('id', 'prefix', 'code')->get() as $row) {
            DB::table('packaging')->where('id', $row->id)->update([
                'code' => $row->prefix.$row->code,
            ]);
        }

        Schema::table('packaging', function (Blueprint $table) {
            $table->dropUnique(['prefix', 'code']);
            $table->dropIndex(['reprocess_of']);
            $table->dropColumn(['prefix', 'reprocess_of']);
            $table->unique('code');
        });
    }
};
