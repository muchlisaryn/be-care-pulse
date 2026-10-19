<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu BMHP satuan di dalam sebuah paket BMHP beserta jumlahnya. Satuan & harga
 * dibaca dari master BMHP, tidak disalin ke sini.
 */
class PackageBmhpItem extends Model
{
    use HasAuditColumns;

    protected $table = 'mst_package_bmhp_item';

    protected $fillable = [
        'package_bmhp_id',
        'bmhp_id',
        'qty',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        // Desimal di database (12,2); dikirim sebagai angka, bukan string.
        'qty' => 'float',
    ];

    public function package()
    {
        return $this->belongsTo(PackageBmhp::class, 'package_bmhp_id');
    }

    /**
     * BMHP yang sudah dihapus dari master tetap terbaca, agar isi paket lama
     * tidak mendadak kehilangan namanya.
     */
    public function bmhp()
    {
        return $this->belongsTo(Bmhp::class, 'bmhp_id')->withTrashed();
    }
}
