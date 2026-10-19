<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class SterilizationBmhp extends Model
{
    use HasAuditColumns, HasAutoCode;

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
     * Kode unik STR-BMHP-yymmdd-sequence
     */
    protected static function generateUniqueCode($model): string
    {
        $prefix = 'STR-BMHP-'.now()->format('ymd');

        $used = static::withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->flip();

        $sequence = 1;
        while ($used->has($sequence)) {
            $sequence++;
        }

        return $prefix.str_pad($sequence, 2, '0', STR_PAD_LEFT);
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
