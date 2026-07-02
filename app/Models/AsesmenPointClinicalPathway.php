<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsesmenPointClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'clinical_pathway_assessment_points';

    protected $fillable = [
        'assessment_id', 'point_id', 'checked_days', 'note',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'checked_days' => 'array',
    ];

    /** Asesmen pemilik nilai ceklis ini. */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AsesmenClinicalPathway::class, 'assessment_id');
    }

    /** Poin clinical pathway yang dinilai. */
    public function point(): BelongsTo
    {
        return $this->belongsTo(PointClinicalPathway::class, 'point_id');
    }
}
