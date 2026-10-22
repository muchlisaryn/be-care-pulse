<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris permintaan order BMHP (`order.order_type = bmhp`): BMHP satuan atau paket
 * BMHP beserta jumlahnya. Order instrumen memakai `order_request_item`.
 */
class OrderRequestBmhp extends Model
{
    use HasAuditColumns;

    public const TYPE_SATUAN = 'satuan';

    public const TYPE_PAKET = 'paket';

    protected $table = 'order_request_bmhp';

    protected $fillable = [
        'order_id',
        'type',
        'bmhp_id',
        'package_bmhp_id',
        'item_name',
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

    public function bmhp()
    {
        return $this->belongsTo(Bmhp::class, 'bmhp_id');
    }

    public function packageBmhp()
    {
        return $this->belongsTo(PackageBmhp::class, 'package_bmhp_id');
    }
}
