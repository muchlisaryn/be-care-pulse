<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master status anggota (STS1 aktif, dst). */
class MemberStatus extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'member_statuses';

    protected $fillable = ['code', 'name'];

    protected static array $legacyAttributes = ['kode' => 'code', 'nama' => 'name'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'member_status_id');
    }
}
