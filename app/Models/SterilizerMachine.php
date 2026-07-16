<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Master mesin sterilisator (autoclave) — tahap Sterilization. Kode auto STL-NNN.
 * Suhu & durasi standar dipakai sebagai acuan operator saat menjalankan &
 * memvalidasi batch sterilisasi. Masa simpan steril BUKAN urusan mesin: tgl
 * kedaluwarsa ditentukan jenis kemasan (lihat master PackagingType).
 */
class SterilizerMachine extends Model
{
    use HasAuditColumns, HasAutoCode;

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    protected $fillable = [
        'code',
        'name',
        'location',
        'temperature',
        'duration_minutes',
        'status',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'duration_minutes' => 'integer',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'STL-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'STL-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
