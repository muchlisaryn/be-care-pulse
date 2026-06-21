<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class Icd10 extends Model
{
    use HasAuditColumns;

    protected $table = 'icd10';

    protected $fillable = ['code', 'display', 'version', 'created_by', 'updated_by'];
}
