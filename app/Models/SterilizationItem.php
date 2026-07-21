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
        // Nomor label kemasan asal unit ini (packaging_item.barcode_no) — dipakai
        // menelusuri label mana yang harus dikemas ulang saat unitnya gagal steril.
        'packaging_barcode',
        'result',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
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
