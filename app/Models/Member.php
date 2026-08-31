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
        'merged_at' => 'datetime',
        'disabled' => 'boolean',
    ];

    /**
     * `disabled`, `merged_at`, dan `merged_to_member_id` SENGAJA tidak ada di
     * `$fillable`.
     *
     * `disabled` diturunkan event `saving` di bawah — kalau ia bisa ditumpangi
     * mass assignment dari request, ia bisa berselisih dengan sebab yang
     * sebenarnya. Dua kolom lainnya hanya boleh disentuh `GabungAnggotaController`
     * di dalam satu transaksi database bersama pemindahan transaksinya; diisi
     * dari form biasa, anggota bisa tampak "sudah digabungkan" tanpa satu pun
     * transaksi benar-benar berpindah.
     */
    protected static function booted(): void
    {
        parent::booted();

        // Anggota yang tidak boleh muncul lagi di mana pun: sudah dihapus, atau
        // seluruh transaksinya sudah dipindahkan lewat Gabung Anggota.
        //
        // Ditulis lewat `saving` supaya JALUR APA PUN yang menyimpan model
        // meninggalkannya konsisten — pola yang sama dengan
        // `MarksDisabledWhenDeleted` pada tabel transaksi.
        static::saving(function (self $model) {
            $model->disabled = $model->deleted_by !== null || $model->merged_at !== null;
        });

        // Anggota nonaktif tidak pernah ikut query mana pun kecuali diminta
        // eksplisit lewat `withDisabled()`. Ini yang memenuhi janji "yang muncul
        // di aplikasi hanya anggota yang disabled-nya false" di SATU tempat,
        // alih-alih menitipkannya ke tiap pemanggil — yang cepat atau lambat
        // ada satu yang lupa.
        static::addGlobalScope('enabled', function ($query) {
            $query->where($query->qualifyColumn('disabled'), false);
        });
    }

    /**
     * Ikutkan anggota nonaktif.
     *
     * WAJIB dipakai di tiga tempat, dan ketiganya bukan "menampilkan di
     * aplikasi" melainkan menjaga keutuhan data:
     *
     *  1. pemeriksaan keunikan & pembuatan `member_number` — nomor anggota
     *     nonaktif tetap terpakai di database, dan mengabaikannya berarti
     *     penyimpanan berikutnya menabrak index unik;
     *  2. relasi riwayat penggabungan — yang justru menunjuk anggota nonaktif;
     *  3. Gabung Anggota itu sendiri, saat membaca kembali anggota asal.
     */
    public function scopeWithDisabled($query)
    {
        return $query->withoutGlobalScope('enabled');
    }

    /** Hanya anggota nonaktif — dipakai layar riwayat penggabungan. */
    public function scopeOnlyDisabled($query)
    {
        return $query->withoutGlobalScope('enabled')
            ->where($query->qualifyColumn('disabled'), true);
    }

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

    /** Penggabungan yang MENGAMBIL transaksi anggota ini. */
    public function mergesAsSource(): HasMany
    {
        return $this->hasMany(MemberMerge::class, 'source_member_id');
    }

    /** Penggabungan yang MENERIMA transaksi anggota lain ke anggota ini. */
    public function mergesAsTarget(): HasMany
    {
        return $this->hasMany(MemberMerge::class, 'target_member_id');
    }

    /** Anggota tujuan tempat seluruh transaksinya kini berada, bila pernah digabungkan. */
    public function mergedTo(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'merged_to_member_id')->withDisabled();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'member_id');
    }
}
