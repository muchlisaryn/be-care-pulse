<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Unit fisik yang dikunci ke satu batch produksi (menggantikan order_item pada
 * pipeline pemrosesan). Unit mengalir lewat pipeline via code tahap.
 */
class ProductionItem extends Model
{
    use HasAuditColumns;

    protected $table = 'production_item';

    protected $fillable = [
        'production_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'condition_out_id',
        'created_by',
        'updated_by',
    ];

    /** Batch produksi pemilik unit ini. */
    public function production()
    {
        return $this->belongsTo(Production::class);
    }

    /** Unit stok instrumen yang dikunci. */
    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    /** Kondisi unit saat keluar. */
    public function conditionOut()
    {
        return $this->belongsTo(Condition::class, 'condition_out_id');
    }
}
