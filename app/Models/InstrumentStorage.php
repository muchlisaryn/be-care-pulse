<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Penempatan unit steril pada lokasi rak penyimpanan (Tahap 5 — Storage).
 * Satu baris = satu unit di satu rak. Lihat migration create_instrument_storages.
 */
class InstrumentStorage extends Model
{
    use HasAuditColumns;

    public const STATUS_TERSIMPAN = 'tersimpan';

    public const STATUS_KELUAR = 'keluar';

    protected $fillable = [
        'order_id',
        'sterilization_id',
        'instrument_stock_id',
        'rack_code',
        'expiry_date',
        'status',
        'stored_at',
        'removed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'stored_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sterilization()
    {
        return $this->belongsTo(Sterilization::class);
    }
}
