<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail per-unit tahap Cleaning (washing). Satu baris per unit fisik dalam sebuah
 * batch cleaning. `disabled = true` menandai unit di-void di tahap ini (mudah dilacak).
 */
class WashingItem extends Model
{
    use HasAuditColumns;

    protected $table = 'washing_item';

    protected $fillable = [
        'washing_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    public function washing()
    {
        return $this->belongsTo(OrderWashing::class, 'washing_id');
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }
}
