<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class Distribution extends Model
{
    use HasAuditColumns, HasAutoCode;

    public const STATUS_TERDISTRIBUSI = 'terdistribusi';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const STATUSES = [
        self::STATUS_TERDISTRIBUSI,
        self::STATUS_DIBATALKAN,
    ];

    protected $fillable = [
        'code',
        'room_id',
        'sender',
        'receiver',
        'distributed_at',
        'status',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'distributed_at' => 'datetime',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'DST-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'DST-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function items()
    {
        return $this->hasMany(DistributionItem::class);
    }
}
