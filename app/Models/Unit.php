<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Master satuan (pcs, box, botol, ...). Kode diisi pengguna lewat form; SAT-NNN
 * hanya cadangan bila dibuat tanpa kode (mis. dari seeder). Jejak pembuat,
 * pengubah & penghapusnya diurus HasAuditColumns — penghapusan selalu soft delete.
 */
class Unit extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $fillable = [
        'code',
        'name',
        'note',
        'created_by',
        'updated_by',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'SAT-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'SAT-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
