<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'point_clinical_pathway';

    /** Pilihan pengisi poin. */
    public const PENGISI = ['dokter', 'perawat', 'farmasi', 'ahli_gizi', 'penunjang'];

    protected $fillable = [
        'template_id', 'categori_id', 'parent_id', 'label', 'pengisi', 'hari_wajib', 'urutan',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'hari_wajib' => 'array',
        'urutan' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateClinicalPathway::class, 'template_id');
    }

    public function categori(): BelongsTo
    {
        return $this->belongsTo(CategoriClinicalPathway::class, 'categori_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('urutan')->orderBy('id');
    }
}
