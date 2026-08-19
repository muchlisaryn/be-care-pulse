<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master wilayah. Kode wilayah tetap dipakai sebagai kode bisnis & kunci URL. */
class Region extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'regions';

    protected $fillable = ['code', 'name'];

    /** API tetap memakai `kode` & `nama`. */
    protected static array $legacyAttributes = ['kode' => 'code', 'nama' => 'name'];

    /** URL master tetap memakai kode, bukan id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'region_id');
    }
}
