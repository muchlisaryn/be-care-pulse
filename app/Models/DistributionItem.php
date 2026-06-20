<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class DistributionItem extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'distribution_id',
        'bmhp_id',
        'quantity',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function distribution()
    {
        return $this->belongsTo(Distribution::class);
    }

    public function bmhp()
    {
        return $this->belongsTo(Bmhp::class);
    }
}
