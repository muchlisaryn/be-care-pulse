<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;

/** Master kota (dipakai sebagai kota lahir anggota). */
class City extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'cities';

    protected $fillable = ['code', 'name'];

    protected static array $legacyAttributes = ['kode' => 'code', 'nama' => 'name'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
