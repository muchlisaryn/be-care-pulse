<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class ProductionBmhpItem extends Model
{
    use HasAuditColumns;

    protected $table = 'production_bmhp_item';

    protected $fillable = [
        'production_bmhp_id',
        'source',
        'bmhp_id',
        'package_bmhp_id',
        'name',
        'package_name',
        'package_no',
        'quantity',
        'unit_name',
        'price',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'package_no' => 'integer',
        'price' => 'float',
    ];

    public function production()
    {
        return $this->belongsTo(ProductionBmhp::class, 'production_bmhp_id');
    }

    public function bmhp()
    {
        return $this->belongsTo(Bmhp::class, 'bmhp_id')->withTrashed();
    }

    public function package()
    {
        return $this->belongsTo(PackageBmhp::class, 'package_bmhp_id')->withTrashed();
    }
}
