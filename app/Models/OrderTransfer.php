<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class OrderTransfer extends Model
{
    use HasAuditColumns;

    protected $table = 'order_transfers';

    // Status permintaan pinjam-alih.
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELED,
    ];

    protected $fillable = [
        'from_order_id',
        'holder_user_id',
        'requested_by_user_id',
        'to_room_id',
        'borrowed_by',
        'medical_record_no',
        'patient_name',
        'note',
        'status',
        'responded_at',
        'new_order_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function fromOrder()
    {
        return $this->belongsTo(Order::class, 'from_order_id');
    }

    public function newOrder()
    {
        return $this->belongsTo(Order::class, 'new_order_id');
    }

    public function holder()
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function toRoom()
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    public function items()
    {
        return $this->hasMany(OrderTransferItem::class);
    }
}
