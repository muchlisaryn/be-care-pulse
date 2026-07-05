<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jejak lengkap (append-only) semua user yang memproses tiap tahap pipeline
 * sampai selesai. Satu baris = satu aksi user pada satu record tahap. Tidak
 * memakai HasAuditColumns karena bersifat historis (append-only).
 */
class PipelineEvent extends Model
{
    // Tahap pipeline.
    public const STAGE_PRODUCTION = 'production';

    public const STAGE_WASHING = 'washing';

    public const STAGE_PACKAGING = 'packaging';

    public const STAGE_STERILIZATION = 'sterilization';

    // Aksi umum antar-tahap.
    public const ACTION_DIBUAT = 'dibuat';

    public const ACTION_DIPROSES = 'diproses';

    public const ACTION_SELESAI = 'selesai';

    public const ACTION_GAGAL = 'gagal';

    public const ACTION_BATAL = 'batal';

    protected $table = 'pipeline_events';

    // Append-only: hanya created_at, tanpa updated_at.
    public $timestamps = false;

    protected $fillable = [
        'stage',
        'code',
        'action',
        'actor',
        'note',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Helper pencatat jejak tahap. actor otomatis diisi dari user yang login bila
     * tidak diberikan; created_at otomatis now().
     */
    public static function record(string $stage, string $code, string $action, array $attributes = []): self
    {
        return static::create([
            'stage' => $stage,
            'code' => $code,
            'action' => $action,
            'actor' => $attributes['actor'] ?? (auth()->user()?->name),
            'note' => $attributes['note'] ?? null,
            'created_at' => $attributes['created_at'] ?? now(),
        ]);
    }
}
