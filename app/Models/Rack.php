<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Master rak gudang steril CSSD — pilihan lokasi rak saat menyimpan unit steril
 * ke gudang ("Simpan ke Gudang"). Hanya menyimpan nama rak & keterangan.
 */
class Rack extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'name',
        'note',
        'created_by',
        'updated_by',
    ];
}
