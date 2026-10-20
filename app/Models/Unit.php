<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Master satuan (pcs, box, botol, ...). Jejak pembuat, pengubah & penghapusnya
 * diurus HasAuditColumns — penghapusan selalu soft delete.
 */
class Unit extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'name',
        'note',
        'created_by',
        'updated_by',
    ];
}
