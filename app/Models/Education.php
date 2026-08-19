<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;

/** Master pendidikan. Dirujuk anggota lewat `members.education_id`. */
class Education extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'educations';

    protected $fillable = ['name'];

    /** API tetap memakai `nama`. */
    protected static array $legacyAttributes = ['nama' => 'name'];
}
