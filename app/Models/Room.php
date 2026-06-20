<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $fillable = ['name', 'created_by', 'updated_by'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
