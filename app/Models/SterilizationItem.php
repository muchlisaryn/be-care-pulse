<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class SterilizationItem extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'sterilization_id',
        'instrument_stock_id',
        'result',
        'created_by',
        'updated_by',
    ];

    public function sterilization()
    {
        return $this->belongsTo(Sterilization::class);
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }
}
