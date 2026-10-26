<?php

namespace App\Models;

use App\Traits\GeneratesSterilizationCode;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Siklus sterilisasi BMHP. Kode {kode mesin}-{ddmmyy}-{urut}, mis. AUT01-191026-03
 * — lihat nextCode().
 */
class SterilizationBmhp extends Model
{
    use GeneratesSterilizationCode, HasAuditColumns;

    protected $table = 'sterilization_bmhp';

    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    // Hasil indikator kimia / biologi / Bowie-Dick saat validasi; NULL = tidak dipakai.
    public const INDICATOR_LULUS = 'lulus';

    public const INDICATOR_GAGAL = 'gagal';

    // Alasan batch dinyatakan gagal steril (`failure_reason`).
    public const FAILURE_REASONS = [
        'indikator_kimia',
        'indikator_biologi',
        'bowie_dick',
        'mesin_error',
        'kemasan_basah',
        'kemasan_rusak',
        'lainnya',
    ];

    protected $fillable = [
        'code',
        'machine_id',
        'machine_name',
        'method',
        'cycle_number',
        'temperature',
        'duration_minutes',
        'operator',
        'bio_indicator_lot',
        'chemical_indicator_lot',
        'chemical_indicator_result',
        'bio_indicator_result',
        'bowie_dick_result',
        'status',
        'failure_reason',
        'failure_note',
        'sterilized_at',
        'expiry_date',
        'note',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sterilized_at' => 'datetime',
        'expiry_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'temperature' => 'float',
        'duration_minutes' => 'integer',
        'cycle_number' => 'integer',
    ];

    /**
     * Kode batch berikutnya: {kode mesin}-{ddmmyy}-{urut 2 digit}. Nomor urut
     * dibagi dengan sterilisasi instrumen — lihat GeneratesSterilizationCode.
     */
    public static function nextCode(string $machineCode): string
    {
        return static::nextSterilizationCode($machineCode);
    }

    public function machine()
    {
        return $this->belongsTo(SterilizerMachine::class, 'machine_id');
    }

    public function items()
    {
        return $this->hasMany(SterilizationBmhpItem::class, 'sterilization_bmhp_id');
    }
}
