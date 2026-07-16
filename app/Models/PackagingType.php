<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Master jenis kemasan (linen, pouch, container, ...) — tahap Packaging. Kode auto
 * PKS-NNN. `shelf_life_days` adalah masa simpan sterilnya: jenis yang dipilih
 * operator saat "Selesai Pengemasan" menentukan tgl kedaluwarsa batch
 * (expiry = tgl kemas + shelf_life_days), yang lalu disimpan sebagai snapshot di
 * `packaging.expiry_date`.
 */
class PackagingType extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $fillable = [
        'code',
        'name',
        'shelf_life_days',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'shelf_life_days' => 'integer',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'PKS-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'PKS-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /** Batch packaging yang memakai jenis kemasan ini. */
    public function packagings()
    {
        return $this->hasMany(Packaging::class);
    }
}
