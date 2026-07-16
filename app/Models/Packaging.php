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
        'sterilization_id',
        'operator',
        'chemical_indicator',
        'packaging_type_id',
        'packaged_at',
        'expiry_date',
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
        'expiry_date' => 'date',
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

    /**
     * Jenis kemasan yang dipilih saat pengemasan — masa simpannya menentukan
     * `expiry_date`. Sengaja mengabaikan global scope `active`: jenis kemasan yang
     * sudah dihapus admin harus tetap terbaca di riwayat & label batch lama.
     */
    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class)->withoutGlobalScope('active');
    }

    /** Tahap cleaning asal (via washing_code). */
    public function washing()
    {
        return $this->belongsTo(OrderWashing::class, 'washing_code', 'code');
    }

    /** Batch sterilisasi yang dibuat dari packaging ini (via packaging_code). */
    public function sterilizations()
    {
        return $this->hasMany(Sterilization::class, 'packaging_code', 'code');
    }

    /** Batch sterilisasi (STR) yang menampung packaging ini (banyak PKG → satu STR). */
    public function sterilization()
    {
        return $this->belongsTo(Sterilization::class, 'sterilization_id');
    }
}
