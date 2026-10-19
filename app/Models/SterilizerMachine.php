<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Master mesin sterilisator (autoclave) — tahap Sterilization. Kode diketik
 * petugas (unik), status selalu aktif saat dibuat.
 * Suhu & durasi standar dipakai sebagai acuan operator saat menjalankan &
 * memvalidasi batch sterilisasi. Masa simpan steril BUKAN urusan mesin: tgl
 * kedaluwarsa ditentukan jenis kemasan (lihat master PackagingType).
 */
class SterilizerMachine extends Model
{
    use HasAuditColumns;

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    protected $fillable = [
        'code',
        'name',
        'location',
        'temperature',
        'duration_minutes',
        'status',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'duration_minutes' => 'integer',
    ];
}
