<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Tahap Packaging pada pipeline CSSD (PKG-NNN). Dirangkai ke tahap cleaning
 * sebelumnya lewat washing_code (= washing.code).
 */
class Packaging extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'packaging';

    // Status tahap packaging.
    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    protected $fillable = [
        'code',
        'washing_code',
        'operator',
        'packaged_at',
        'note',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'packaged_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** Kode batch packaging berikutnya: PKG-NNN. */
    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'PKG-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'PKG-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /** Tahap cleaning asal (via washing_code). */
    public function washing()
    {
        return $this->belongsTo(OrderWashing::class, 'washing_code', 'code');
    }
}
