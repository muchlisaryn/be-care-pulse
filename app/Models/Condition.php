<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class Condition extends Model
{
    use HasAuditColumns;

    protected $fillable = ['name', 'created_by', 'updated_by'];
}
