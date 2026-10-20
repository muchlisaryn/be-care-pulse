<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penelusuran gagal steril BMHP, untuk database yang tabelnya dibuat sebelum
     * migrasi create_production_bmhp_tables dirapikan:
     *  - `sterilization_bmhp`: hasil indikator & alasan gagal (+ teks bebas
     *    untuk alasan `lainnya`).
     *  - `packaging_bmhp`: alasan & pelaku void kemasan.
     *  - Kolom yang tidak pernah diisi dibuang: `production_bmhp.status`,
     *    `packaging_bmhp.started_*`, `disabled*` di `packaging_bmhp_item` dan
     *    `sterilization_bmhp_items`.
     *  - `packaging_bmhp.status` dibuang: tahapnya dibaca dari `completed_at`,
     *    `disabled` & `sterilization_bmhp_items`.
     */
    private array $add = [
        'sterilization_bmhp' => [
            'chemical_indicator_result' => 'chemical_indicator_lot',
            'bio_indicator_result' => 'chemical_indicator_result',
            'bowie_dick_result' => 'bio_indicator_result',
            'failure_reason' => 'status',
            'failure_note' => 'failure_reason',
        ],
        'packaging_bmhp' => [
            'disabled_reason' => 'disabled_at',
            'disabled_by' => 'disabled_reason',
        ],
    ];

    private array $drop = [
        'production_bmhp' => ['status'],
        'packaging_bmhp' => ['status', 'started_by', 'started_at'],
        'packaging_bmhp_item' => ['disabled', 'disabled_at'],
        'sterilization_bmhp_items' => ['disabled', 'disabled_at'],
    ];

    public function up(): void
    {
        foreach ($this->add as $tableName => $columns) {
            foreach ($columns as $column => $after) {
                if (Schema::hasColumn($tableName, $column)) {
                    continue;
                }
                Schema::table($tableName, function (Blueprint $table) use ($column, $after) {
                    $table->string($column)->nullable()->after($after);
                });
            }
        }

        // Sebelum ini kemasan BMHP hanya di-void karena gagal steril.
        DB::table('packaging_bmhp')
            ->where('disabled', true)
            ->whereNull('disabled_reason')
            ->update(['disabled_reason' => 'gagal_steril']);

        foreach ($this->drop as $tableName => $columns) {
            $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($tableName, $c)));
            if ($existing) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->add as $tableName => $columns) {
            $existing = array_values(array_filter(array_keys($columns), fn ($c) => Schema::hasColumn($tableName, $c)));
            if ($existing) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
            }
        }
        // Kolom yang dibuang sengaja tidak dikembalikan — tidak pernah dipakai.
    }
};
