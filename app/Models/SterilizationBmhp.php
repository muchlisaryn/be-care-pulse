<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Siklus sterilisasi BMHP. Kode {kode mesin}-{ddmmyy}-{urut}, mis. AUT01-191026-03
 * — lihat nextCode().
 */
class SterilizationBmhp extends Model
{
    use HasAuditColumns;

    protected $table = 'sterilization_bmhp';

    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

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
        'status',
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
     * Kode batch berikutnya: {kode mesin}-{ddmmyy}-{urut 2 digit}. Nomor urut =
     * urutan sterilisasi BMHP hari itu, lintas mesin (sterilisasi ke-3 hari ini → 03).
     */
    public static function nextCode(string $machineCode): string
    {
        $day = now()->format('dmy');
        $pattern = '/-'.$day.'-(\d+)$/';

        $last = static::withoutGlobalScopes()
            ->where('code', 'like', '%-'.$day.'-%')
            ->pluck('code')
            ->map(fn ($code) => preg_match($pattern, $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $machineCode.'-'.$day.'-'.str_pad($last + 1, 2, '0', STR_PAD_LEFT);
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
