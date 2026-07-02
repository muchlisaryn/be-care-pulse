<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class CategoriClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'clinical_pathway_categories';

    protected $fillable = ['sort_order', 'label', 'created_by', 'updated_by'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
