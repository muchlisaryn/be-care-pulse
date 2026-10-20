<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `packaging_bmhp.disabled_reason` jadi JSON berisi rincian void lengkap:
     * { reason, packaging {id, code}, sterilization {id, code, failure_reason,
     *   failure_note}, replaced_by {id, code}, by, at }.
     *
     * Baris lama (teks `gagal_steril`) dilengkapi dari data yang ada: siklus steril
     * = item sterilisasi kemasan itu yang `result = gagal`; pengganti = kemasan GS-
     * pertama dari produksi yang sama yang dibuat sesudahnya. Catatan otomatis lama
     * ("Kemas ulang dari …" / "Gagal sterilisasi …") dibuang — isinya kini di JSON.
     */
    public function up(): void
    {
        // Teks dulu: JSON rinciannya tak muat di varchar(255), dan nilai lama
        // (`gagal_steril`) bukan JSON valid sehingga belum bisa langsung ke json.
        Schema::table('packaging_bmhp', function (Blueprint $table) {
            $table->text('disabled_reason')->nullable()->change();
        });

        $rows = DB::table('packaging_bmhp')
            ->whereNotNull('disabled_reason')
            ->where('disabled_reason', 'not like', '{%')
            ->get();

        foreach ($rows as $row) {
            $steril = DB::table('sterilization_bmhp_items as i')
                ->join('sterilization_bmhp as s', 's.id', '=', 'i.sterilization_bmhp_id')
                ->where('i.packaging_bmhp_id', $row->id)
                ->where('i.result', 'gagal')
                ->orderByDesc('s.id')
                ->first(['s.id', 's.code', 's.failure_reason', 's.failure_note']);

            $replacement = DB::table('packaging_bmhp')
                ->where('production_bmhp_id', $row->production_bmhp_id)
                ->where('id', '>', $row->id)
                ->where('prefix', 'like', 'GS-%')
                ->orderBy('id')
                ->first(['id', 'prefix', 'code']);

            DB::table('packaging_bmhp')->where('id', $row->id)->update([
                'disabled_reason' => json_encode([
                    'reason' => $row->disabled_reason,
                    'packaging' => ['id' => $row->id, 'code' => $row->prefix.$row->code],
                    'sterilization' => $steril ? [
                        'id' => $steril->id,
                        'code' => $steril->code,
                        'failure_reason' => $steril->failure_reason,
                        'failure_note' => $steril->failure_note,
                    ] : null,
                    'replaced_by' => $replacement
                        ? ['id' => $replacement->id, 'code' => $replacement->prefix.$replacement->code]
                        : null,
                    'by' => $row->disabled_by,
                    'at' => $row->disabled_at,
                ]),
            ]);
        }

        // Catatan yang dulu diisi otomatis saat gagal steril / kemas ulang.
        DB::table('packaging_bmhp')->where('note', 'like', 'Kemas ulang dari %')->update(['note' => null]);
        DB::table('packaging_bmhp')->where('note', 'like', '%Gagal sterilisasi %')->get(['id', 'note'])
            ->each(function ($row) {
                $note = trim(preg_replace('/(\s*\|\s*)?Gagal sterilisasi \S+/', '', $row->note));
                DB::table('packaging_bmhp')->where('id', $row->id)->update(['note' => $note !== '' ? $note : null]);
            });

        Schema::table('packaging_bmhp', function (Blueprint $table) {
            $table->json('disabled_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('packaging_bmhp', function (Blueprint $table) {
            $table->text('disabled_reason')->nullable()->change();
        });
    }
};
