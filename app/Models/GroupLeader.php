<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master ketua kelompok. Kodenya dulu bernama `noketua`. */
class GroupLeader extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    /**
     * Nama ketua penampung anggota perorangan — bukan kelompok sungguhan.
     *
     * Dicocokkan **persis**: master ini juga memuat nama orang yang kebetulan
     * mengandung kata itu (mis. "Filosa Idham Pribadi").
     */
    public const NAMA_PRIBADI = 'Pribadi';

    protected $table = 'group_leaders';

    protected $fillable = ['code', 'name', 'gender', 'address', 'phone'];

    protected static array $legacyAttributes = [
        'noketua' => 'code',
        'nama' => 'name',
        'jenis_kelamin' => 'gender',
        'alamat' => 'address',
        'telepon' => 'phone',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'group_leader_id');
    }

    /** Ketua kelompok sungguhan — di luar penampung anggota perorangan. */
    public function scopeKelompok(Builder $query): Builder
    {
        return $query->where('name', '!=', self::NAMA_PRIBADI);
    }
}
