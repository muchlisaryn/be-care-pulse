<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class PackagingBmhp extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'packaging_bmhp';

    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_STERILISASI = 'sterilisasi';

    protected $fillable = [
        'production_bmhp_id',
        'prefix',
        'code',
        'packaging_type_id',
        'chemical_indicator',
        'operator',
        'packaged_at',
        'expiry_date',
        'note',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'packaged_at' => 'datetime',
        'expiry_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    /**
     * Kode unik Packaging BMHP
     */
    protected static function generateUniqueCode($model): string
    {
        $day = now()->format('ymd');

        $used = static::withoutGlobalScopes()
            ->where('code', 'like', $day.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($day)))
            ->flip();

        $sequence = 1;
        while ($used->has($sequence)) {
            $sequence++;
        }

        return $day.str_pad($sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Kode berikutnya (tanpa prefix) — dipakai sebelum kemasan dibuat, karena kode
     * produksi BMHP = BMHP-{prefix}-{kode kemasan ini}.
     */
    public static function nextCode(): string
    {
        return static::generateUniqueCode(new static);
    }

    public function getFullCodeAttribute(): string
    {
        return $this->prefix.$this->code;
    }

    public function barcodeNoFor(?int $packageNo): string
    {
        return $this->prefix.$this->code.($packageNo ?? '');
    }

    public function production()
    {
        return $this->belongsTo(ProductionBmhp::class, 'production_bmhp_id');
    }

    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class, 'packaging_type_id');
    }

    public function items()
    {
        return $this->hasMany(PackagingBmhpItem::class, 'packaging_bmhp_id');
    }

    public function sterilizationItems()
    {
        return $this->hasMany(SterilizationBmhpItem::class, 'packaging_bmhp_id');
    }
}
