<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VarianClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'clinical_pathway_variances';

    protected $fillable = [
        'assessment_id', 'occurred_at', 'variance', 'reason', 'initials',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /** Asesmen pemilik catatan varian ini. */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AsesmenClinicalPathway::class, 'assessment_id');
    }
}
