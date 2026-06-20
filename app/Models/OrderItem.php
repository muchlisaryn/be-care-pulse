<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasAuditColumns;

    protected $table = 'order_item';

    protected $fillable = [
        'order_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'condition_out_id',
        'condition_in_id',
        'is_returned',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_returned' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    public function conditionOut()
    {
        return $this->belongsTo(Condition::class, 'condition_out_id');
    }

    public function conditionIn()
    {
        return $this->belongsTo(Condition::class, 'condition_in_id');
    }
}
