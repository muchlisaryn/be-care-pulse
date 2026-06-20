<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class InstrumentCatalogItem extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'instrument_catalog_id',
        'instrument_id',
        'quantity',
        'standard_condition_id',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function catalog()
    {
        return $this->belongsTo(InstrumentCatalog::class, 'instrument_catalog_id');
    }

    public function instrument()
    {
        return $this->belongsTo(Instrument::class);
    }

    public function standardCondition()
    {
        return $this->belongsTo(Condition::class, 'standard_condition_id');
    }
}
