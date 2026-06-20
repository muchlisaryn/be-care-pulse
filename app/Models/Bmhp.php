<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class Bmhp extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'stock_qty',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stock_qty' => 'integer',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'BMHP-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'BMHP-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
