<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsesmenClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'asesmen_clinical_pathway';

    /** Pilihan jenis kelamin. */
    public const JENIS_KELAMIN = ['L', 'P'];

    protected $fillable = [
        'template_id', 'no_rm', 'nama_pasien', 'jenis_kelamin', 'tanggal_lahir',
        'diagnosa_masuk', 'penyakit_utama', 'penyakit_penyerta', 'komplikasi',
        'tindakan', 'bb', 'tb', 'tanggal_jam_masuk', 'tanggal_jam_keluar',
        'lama_rawat', 'rencana_rawat', 'ruang_id', 'kelas', 'rujukan',
        'verifikasi_dokter_by', 'verifikasi_dokter_at',
        'verifikasi_perawat_by', 'verifikasi_perawat_at',
        'verifikasi_pelaksana_by', 'verifikasi_pelaksana_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tanggal_lahir' => 'date',
        'tanggal_jam_masuk' => 'datetime',
        'tanggal_jam_keluar' => 'datetime',
        'bb' => 'decimal:2',
        'tb' => 'decimal:2',
        'lama_rawat' => 'integer',
        'rujukan' => 'boolean',
        'verifikasi_dokter_at' => 'datetime',
        'verifikasi_perawat_at' => 'datetime',
        'verifikasi_pelaksana_at' => 'datetime',
    ];

    /** Formulir/template yang dipakai asesmen ini. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateClinicalPathway::class, 'template_id');
    }

    /** Ruang rawat (master ruangan). */
    public function ruang(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'ruang_id');
    }

    /** Nilai ceklis per poin. */
    public function points(): HasMany
    {
        return $this->hasMany(AsesmenPointClinicalPathway::class, 'asesmen_id');
    }

    /** Catatan varian (penyimpangan) clinical pathway. */
    public function varians(): HasMany
    {
        return $this->hasMany(VarianClinicalPathway::class, 'asesmen_id');
    }
}
