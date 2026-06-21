<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VarianClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'varian_clinical_pathway';

    protected $fillable = [
        'asesmen_id', 'tanggal_waktu', 'varian', 'alasan', 'paraf',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tanggal_waktu' => 'datetime',
    ];

    /** Asesmen pemilik catatan varian ini. */
    public function asesmen(): BelongsTo
    {
        return $this->belongsTo(AsesmenClinicalPathway::class, 'asesmen_id');
    }
}
