<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penempatan BMHP steril pada lokasi rak penyimpanan di Gudang Steril BMHP.
 *
 * Tanpa kolom `status` dan `expiry_date`: masih di rak dibaca dari `removed_at` /
 * `disabled_at` (lihat sterilePool), kedaluwarsanya dari kemasannya
 * (`packaging_bmhp.expiry_date`, lihat usableOn).
 */
class StorageBmhp extends Model
{
    use HasAuditColumns;

    protected $table = 'storage_bmhp';

    protected $fillable = [
        'sterilization_bmhp_id',
        'packaging_bmhp_id',
        'packaging_bmhp_item_id',
        'production_bmhp_item_id',
        'barcode_no',
        'rack_id',
        'stored_at',
        'removed_at',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stored_at' => 'datetime',
        'removed_at' => 'datetime',
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    public function sterilization(): BelongsTo
    {
        return $this->belongsTo(SterilizationBmhp::class, 'sterilization_bmhp_id');
    }

    public function packaging(): BelongsTo
    {
        return $this->belongsTo(PackagingBmhp::class, 'packaging_bmhp_id');
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingBmhpItem::class, 'packaging_bmhp_item_id');
    }

    public function productionItem(): BelongsTo
    {
        return $this->belongsTo(ProductionBmhpItem::class, 'production_bmhp_item_id');
    }

    /** Rak tempat BMHP ini disimpan (master `racks`). */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class, 'rack_id')->withTrashed();
    }

    /**
     * Masih bisa dipakai pada tanggal `$date`: kemasannya belum kedaluwarsa
     * (atau tidak bertanggal kedaluwarsa).
     */
    public function scopeUsableOn($query, $date)
    {
        return $query->whereHas('packaging', fn ($p) => $p->where(
            fn ($w) => $w->whereNull('expiry_date')->orWhere('expiry_date', '>=', $date)
        ));
    }

    /**
     * Stok steril yang aktif berada di rak penyimpanan (belum keluar, belum dihapus, belum di-void).
     */
    public function scopeSterilePool($query)
    {
        return $query->whereNull($query->qualifyColumn('deleted_by'))
            ->whereNull($query->qualifyColumn('removed_at'))
            ->whereNull($query->qualifyColumn('disabled_at'));
    }

    public function scopeStillInRack($query)
    {
        return $query->whereNull($query->qualifyColumn('deleted_by'))
            ->whereNull($query->qualifyColumn('removed_at'))
            ->whereNull($query->qualifyColumn('disabled_at'));
    }
}
