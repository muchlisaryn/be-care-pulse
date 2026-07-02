<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'clinical_pathway_templates';

    protected $fillable = ['icd10_id', 'max_days', 'description', 'is_active', 'created_by', 'updated_by'];

    protected $casts = [
        'max_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Diagnosa (referensi ke master ICD 10). */
    public function icd10(): BelongsTo
    {
        return $this->belongsTo(Icd10::class, 'icd10_id');
    }

    /** Poin formulir milik template ini. */
    public function points(): HasMany
    {
        return $this->hasMany(PointClinicalPathway::class, 'template_id');
    }
}
