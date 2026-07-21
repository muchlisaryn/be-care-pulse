<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail per-unit tahap Packaging. Satu baris per unit fisik dalam sebuah batch
 * packaging. `disabled = true` menandai unit di-void di tahap ini (mis. PKG lama saat
 * unit gagal steril diproses ulang) sehingga mudah dilacak per unit.
 *
 * `barcode_no` = nomor yang tercetak di label kemasan (prefix + kode packaging +
 * nomor set, tanpa spasi). Tidak unik: unit-unit dalam satu set berbagi satu label,
 * jadi berbagi nomor yang sama juga.
 */
class PackagingItem extends Model
{
    use HasAuditColumns;

    protected $table = 'packaging_item';

    protected $fillable = [
        'packaging_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'barcode_no',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    public function packaging()
    {
        return $this->belongsTo(Packaging::class, 'packaging_id');
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }
}
