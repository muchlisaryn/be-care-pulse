<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Penempatan unit steril pada lokasi rak penyimpanan (Tahap 5 — Storage).
 * Satu baris = satu unit di satu rak. Lihat migration create_instrument_storages.
 *
 * Identitas unit TIDAK disimpan di sini: baris ini menunjuk ke `production_item`
 * (baris batch produksi asal unit), dan dari sanalah `name`, `source`,
 * `package_name`, `kode_instrumen` serta unit fisiknya (`instrument_stock_id`)
 * dibaca. Query SQL yang butuh kolom-kolom itu harus JOIN ke `production_item`.
 */
class InstrumentStorage extends Model
{
    use HasAuditColumns;

    public const STATUS_TERSIMPAN = 'tersimpan';

    public const STATUS_KELUAR = 'keluar';

    protected $fillable = [
        'order_id',
        'sterilization_id',
        'production_item_id',
        // Turunan dari production_item.instrument_stock_id — selalu diisi bersamaan
        // dengan production_item_id, jangan diisi terpisah.
        'instrument_stock_id',
        'rack_code',
        'expiry_date',
        'status',
        'stored_at',
        'removed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'stored_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    /** Baris batch produksi asal unit — sumber nama, kode, asal & nama paket. */
    public function productionItem()
    {
        return $this->belongsTo(ProductionItem::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sterilization()
    {
        return $this->belongsTo(Sterilization::class);
    }

    /** Unit fisik yang ditempatkan di rak ini. */
    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    /**
     * SENGAJA TIDAK ADA accessor `name` / `source` / `package_name` di model ini.
     * Accessor dengan nama tersebut akan MEMBAYANGI alias SQL bernama sama:
     * hasil `selectRaw(... as package_name)` dan `pluck('...package_name')`
     * dipetakan Eloquent lewat mutator sehingga nilainya jadi null tanpa error.
     * Baca lewat relasinya: `$storage->productionItem->source` / `->package_name`
     * / `->name`.
     */
}
