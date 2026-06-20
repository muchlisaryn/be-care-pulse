<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class OrderTransferItem extends Model
{
    use HasAuditColumns;

    protected $table = 'order_transfer_items';

    protected $fillable = [
        'order_transfer_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'created_by',
        'updated_by',
    ];

    public function transfer()
    {
        return $this->belongsTo(OrderTransfer::class, 'order_transfer_id');
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }
}
