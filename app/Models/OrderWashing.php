<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Catatan pencucian (Cleaning) untuk satu order. Lihat migration
 * create_order_washing_table untuk rincian kolom.
 */
class OrderWashing extends Model
{
    use HasAuditColumns;

    protected $table = 'order_washing';

    // Status proses pencucian.
    public const STATUS_DALAM_PROSES = 'dalam_proses';

    public const STATUS_SELESAI = 'selesai';

    protected $fillable = [
        'order_id',
        'machine_no',
        'operator',
        'temperature',
        'washed_at',
        'detergent_type',
        'status',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'washed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
