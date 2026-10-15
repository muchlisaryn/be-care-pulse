<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class PackagingBmhpItem extends Model
{
    use HasAuditColumns;

    protected $table = 'packaging_bmhp_item';

    protected $fillable = [
        'packaging_bmhp_id',
        'production_bmhp_item_id',
        'barcode_no',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    public function packaging()
    {
        return $this->belongsTo(PackagingBmhp::class, 'packaging_bmhp_id');
    }

    public function productionItem()
    {
        return $this->belongsTo(ProductionBmhpItem::class, 'production_bmhp_item_id');
    }
}
