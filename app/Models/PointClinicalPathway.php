<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'clinical_pathway_points';

    /** Pilihan pengisi poin (kolom filled_by). */
    public const FILLED_BY = ['dokter', 'perawat', 'farmasi', 'ahli_gizi', 'penunjang'];

    protected $fillable = [
        'template_id', 'category_id', 'parent_id', 'label', 'filled_by', 'required_days', 'sort_order',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'required_days' => 'array',
        'sort_order' => 'integer',
    ];

    /** Template pemilik poin ini. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateClinicalPathway::class, 'template_id');
    }

    /** Kategori yang menaungi poin ini. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoriClinicalPathway::class, 'category_id');
    }

    /** Poin induk (untuk sub-poin). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Sub-poin di bawah poin ini, diurutkan sesuai sort_order. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }
}
