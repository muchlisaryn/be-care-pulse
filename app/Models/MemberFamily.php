<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anggota keluarga / tanggungan dari seorang anggota. */
class MemberFamily extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'member_families';

    protected $fillable = [
        'member_id', 'leader_name', 'member_number', 'member_name',
        'birth_date', 'gender', 'education',
    ];

    protected $casts = ['birth_date' => 'date'];

    protected static array $legacyAttributes = [
        'anggota_id' => 'member_id',
        'nama_ketua' => 'leader_name',
        'no_anggota' => 'member_number',
        'nama_anggota' => 'member_name',
        'tgl_lahir' => 'birth_date',
        'jenis_kelamin' => 'gender',
        'pendidikan' => 'education',
    ];

    protected static array $legacyRelations = ['anggota' => 'member'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
