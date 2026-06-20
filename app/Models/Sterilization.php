<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class Sterilization extends Model
{
    use HasAuditColumns, HasAutoCode;

    // Status batch sterilisasi
    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    public const STATUSES = [
        self::STATUS_DIPROSES,
        self::STATUS_SELESAI,
        self::STATUS_GAGAL,
    ];

    // Metode sterilisasi
    public const METHOD_UAP = 'uap';           // Steam / autoclave

    public const METHOD_EO = 'eo';            // Ethylene oxide

    public const METHOD_PLASMA = 'plasma';        // Hydrogen peroxide plasma

    public const METHOD_PANAS_KERING = 'panas_kering';  // Dry heat

    public const METHODS = [
        self::METHOD_UAP,
        self::METHOD_EO,
        self::METHOD_PLASMA,
        self::METHOD_PANAS_KERING,
    ];

    protected $fillable = [
        'machine',
        'method',
        'cycle_number',
        'temperature',
        'duration_minutes',
        'operator',
        'sterilized_at',
        'expiry_date',
        'chemical_indicator',
        'biological_indicator',
        'status',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sterilized_at' => 'datetime',
        'expiry_date' => 'date',
        'temperature' => 'decimal:2',
        'duration_minutes' => 'integer',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'STR-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'STR-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    public function items()
    {
        return $this->hasMany(SterilizationItem::class);
    }
}
