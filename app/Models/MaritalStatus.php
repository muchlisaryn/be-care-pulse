<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;

/** Master status nikah. Nilainya disalin sebagai teks ke `members.marital_status`. */
class MaritalStatus extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'marital_statuses';

    protected $fillable = ['name'];

    /** API tetap memakai `nama`. */
    protected static array $legacyAttributes = ['nama' => 'name'];
}
