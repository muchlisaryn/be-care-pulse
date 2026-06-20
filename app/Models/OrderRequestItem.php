<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class OrderRequestItem extends Model
{
    use HasAuditColumns;

    protected $table = 'order_request_item';

    protected $fillable = [
        'order_id',
        'type',
        'instrument_id',
        'instrument_catalog_id',
        'package_name',
        'quantity',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function instrument()
    {
        return $this->belongsTo(Instrument::class);
    }

    public function catalog()
    {
        return $this->belongsTo(InstrumentCatalog::class, 'instrument_catalog_id');
    }
}
