<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anggota Nafsul Muthmainah.
 *
 * Kolom relasi menyimpan id master (`region_id`, `group_leader_id`, …),
 * sedangkan API tetap memakai kode master (`kode_wilayah`, `noketua`, …).
 * Penerjemahan dua arah ditangani HasLegacyAttributes.
 */
class Member extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'members';

    protected $fillable = [
        'region_id', 'group_leader_id', 'birth_city_id', 'member_status_id',
        'education_id', 'occupation_id',
        'family_card_number', 'member_number', 'name',
        'birth_date', 'gender', 'marital_status',
        'id_card_number', 'address', 'phone', 'active_date', 'inactive_date',
        'note', 'family_name', 'relationship', 'family_address', 'family_phone',
        'user_code', 'visit', 'updated_date',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'active_date' => 'date',
        'inactive_date' => 'date',
        'updated_date' => 'date',
    ];

    /** Nama field API (lama) → kolom database (baru). */
    protected static array $legacyAttributes = [
        'kode_wilayah' => ['column' => 'region_id', 'relation' => 'region', 'key' => 'code'],
        'noketua' => ['column' => 'group_leader_id', 'relation' => 'groupLeader', 'key' => 'code'],
        'kode_kota_lahir' => ['column' => 'birth_city_id', 'relation' => 'birthCity', 'key' => 'code'],
        'kode_status' => ['column' => 'member_status_id', 'relation' => 'memberStatus', 'key' => 'code'],
        'nokk' => 'family_card_number',
        'no_anggota' => 'member_number',
        'nama' => 'name',
        'tgl_lahir' => 'birth_date',
        'jenis_kelamin' => 'gender',
        'pendidikan_id' => 'education_id',
        'pekerjaan_id' => 'occupation_id',
        'status_nikah' => 'marital_status',
        'noktp' => 'id_card_number',
        'alamat' => 'address',
        'telepon' => 'phone',
        'tgl_aktif' => 'active_date',
        'tgl_nonaktif' => 'inactive_date',
        'keterangan' => 'note',
        'nama_keluarga' => 'family_name',
        'hubungan' => 'relationship',
        'alamat_keluarga' => 'family_address',
        'telepon_keluarga' => 'family_phone',
        'kode_pengguna' => 'user_code',
        'kunjungan' => 'visit',
        'tgl_update' => 'updated_date',
    ];

    /** Nama relasi di response API (lama) → nama relasi model (baru). */
    protected static array $legacyRelations = [
        'wilayah' => 'region',
        'ketua' => 'groupLeader',
        'kotaLahir' => 'birthCity',
        'status' => 'memberStatus',
        'keluarga' => 'families',
        'pendidikan' => 'education',
        'pekerjaan' => 'occupation',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function groupLeader(): BelongsTo
    {
        return $this->belongsTo(GroupLeader::class, 'group_leader_id');
    }

    public function birthCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'birth_city_id');
    }

    public function memberStatus(): BelongsTo
    {
        return $this->belongsTo(MemberStatus::class, 'member_status_id');
    }

    public function education(): BelongsTo
    {
        return $this->belongsTo(Education::class, 'education_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id');
    }

    public function families(): HasMany
    {
        return $this->hasMany(MemberFamily::class, 'member_id');
    }
}
