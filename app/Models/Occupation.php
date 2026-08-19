<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;

/** Master pekerjaan. Dirujuk anggota lewat `members.occupation_id`. */
class Occupation extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'occupations';

    protected $fillable = ['name'];

    /** API tetap memakai `nama`. */
    protected static array $legacyAttributes = ['nama' => 'name'];
}
