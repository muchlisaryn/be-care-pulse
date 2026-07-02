<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Catatan pencucian (Cleaning) — tahap mandiri pada pipeline CSSD (tabel: washing,
 * code WSH-NNN). Tidak menyimpan order_id; keterkaitan ke order hanya di tahap
 * sterilisasi. Dirangkai ke tahap produksi lewat production_code.
 */
class OrderWashing extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'washing';

    // Status proses pencucian.
    public const STATUS_DALAM_PROSES = 'dalam_proses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    protected $fillable = [
        'code',
        'production_code',
        'washer_machine_id',
        'machine_no',
        'operator',
        'temperature',
        'washed_at',
        'duration_minutes',
        'detergent_type',
        'alert',
        'alert_message',
        'failure_reason',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'washed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_minutes' => 'integer',
        'alert' => 'boolean',
    ];

    /** Kode batch cleaning berikutnya: WSH-NNN. */
    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'WSH-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'WSH-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /** Tahap produksi asal (via production_code). */
    public function production()
    {
        return $this->belongsTo(Production::class, 'production_code', 'code');
    }

    /** Mesin washer yang dipakai. */
    public function washerMachine()
    {
        return $this->belongsTo(WasherMachine::class);
    }
}
