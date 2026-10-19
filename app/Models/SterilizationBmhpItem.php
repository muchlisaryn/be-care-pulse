<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class SterilizationBmhpItem extends Model
{
    use HasAuditColumns;

    protected $table = 'sterilization_bmhp_items';

    public const RESULT_STERIL = 'steril';
    public const RESULT_GAGAL = 'gagal';

    protected $fillable = [
        'sterilization_bmhp_id',
        'packaging_bmhp_id',
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
        return $this->belongsTo(SterilizationBmhp::class, 'sterilization_bmhp_id');
    }

    public function packaging()
    {
        return $this->belongsTo(PackagingBmhp::class, 'packaging_bmhp_id');
    }
}
