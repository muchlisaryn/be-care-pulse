<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsesmenPointClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'asesmen_point_clinical_pathway';

    protected $fillable = [
        'asesmen_id', 'point_id', 'checked_hari', 'keterangan',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'checked_hari' => 'array',
    ];

    public function asesmen(): BelongsTo
    {
        return $this->belongsTo(AsesmenClinicalPathway::class, 'asesmen_id');
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(PointClinicalPathway::class, 'point_id');
    }
}
