<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat (append-only) perubahan status unit instrumen.
 * Sengaja TIDAK memakai HasAuditColumns: log bersifat immutable —
 * tidak di-update / soft-delete, hanya created_by + created_at.
 */
class InstrumentStockLog extends Model
{
    protected $fillable = [
        'instrument_stock_id',
        'from_status',
        'to_status',
        'context',
        'reference_code',
        'note',
        'created_by',
    ];

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }
}
