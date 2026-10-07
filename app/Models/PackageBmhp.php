<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Master paket BMHP — kumpulan BMHP satuan (lihat `items`). Kodenya diketik
 * manual oleh admin; penghapusannya soft delete lewat HasAuditColumns.
 */
class PackageBmhp extends Model
{
    use HasAuditColumns;

    protected $table = 'mst_package_bmhp';

    protected $fillable = [
        'code',
        'name',
        'created_by',
        'updated_by',
    ];

    // Harga paket tidak disimpan: selalu jumlah (harga BMHP × qty) isinya, supaya
    // tidak pernah berselisih dengan harga di master BMHP.
    protected $appends = ['total_price'];

    /** Isi paket yang masih aktif. */
    public function items()
    {
        return $this->hasMany(PackageBmhpItem::class, 'package_bmhp_id');
    }

    public function getTotalPriceAttribute(): float
    {
        // Hanya dihitung bila isinya sudah dimuat — tidak memicu query sendiri.
        if (! $this->relationLoaded('items')) {
            return 0.0;
        }

        return (float) $this->items->sum(
            fn (PackageBmhpItem $item) => $item->qty * ($item->bmhp?->price ?? 0)
        );
    }
}
