<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class CategoriClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'categori_clinical_pathway';

    protected $fillable = ['urutan', 'label', 'created_by', 'updated_by'];

    protected $casts = [
        'urutan' => 'integer',
    ];
}
