<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class ProductionBmhp extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'production_bmhp';

    public const STATUS_DIPROSES = 'diproses';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $fillable = [
        'code',
        'note',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Kode unik PRD-BMHP-yymmdd-sequence
     */
    protected static function generateUniqueCode($model): string
    {
        $prefix = 'PRD-BMHP-'.now()->format('ymd');

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

    public function items()
    {
        return $this->hasMany(ProductionBmhpItem::class, 'production_bmhp_id');
    }

    public function packagings()
    {
        return $this->hasMany(PackagingBmhp::class, 'production_bmhp_id');
    }
}
