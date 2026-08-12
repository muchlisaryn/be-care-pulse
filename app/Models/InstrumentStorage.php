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

    /**
     * DEFINISI TUNGGAL "stok steril yang ada di rak" — dipakai daftar Inventaris
     * Gudang Steril, kartu ringkasannya, dan penyusun kandidat distribusi.
     *
     * Sebelumnya tiap tempat menulis syaratnya sendiri dan ketiganya menyimpang:
     * barang terpajang di Gudang Steril tapi ditolak saat didistribusikan dengan
     * keterangan stok kosong. Selama definisinya cuma ada di sini, itu tidak bisa
     * terjadi lagi — yang boleh berbeda hanyalah perlakuan terhadap baris
     * kedaluwarsa (daftar menampilkannya dengan penanda, distribusi menolaknya).
     *
     * Syaratnya jejak, bukan status unit:
     *  - `deleted_by` NULL — baris gudang belum dihapus;
     *  - `status` = `tersimpan` — status BARIS GUDANG, ditulis sekali saat unit keluar
     *    dari rak (didistribusikan ATAU ditarik kembali ke produksi untuk diproses
     *    ulang, lihat ProductionController::closeStorageForReprocessed). Ini BUKAN
     *    `instrument_stocks.status` yang ditulis ulang di banyak titik sepanjang alur
     *    CSSD dan bisa tertinggal;
     *  - `order_id` NULL — belum diklaim / belum didistribusikan ke order mana pun.
     */
    public function scopeSterilePool($query)
    {
        return $query->whereNull($query->qualifyColumn('deleted_by'))
            ->where($query->qualifyColumn('status'), self::STATUS_TERSIMPAN)
            ->whereNull($query->qualifyColumn('order_id'));
    }

    /**
     * Nomor label kemasan (`sterilization_items.packaging_barcode`) yang SELURUH isinya
     * tidak layak didistribusikan: ada minimal satu unit di bungkus itu yang
     * kedaluwarsa atau tersimpan tanpa tanggal kedaluwarsa.
     *
     * Sterilitas melekat pada BUNGKUS, bukan pada unit. Tanpa aturan ini, bungkus yang
     * tanggal simpan isinya tidak seragam (data lama / unit yang pernah diproses ulang)
     * masih bisa terpakai sebagian: isi yang kebetulan masih bertanggal valid lolos,
     * lalu dirakit jadi "set" dari bungkus yang fisiknya sudah tidak layak.
     *
     * Tinggal di sini — bukan di controller — supaya penyusun kandidat distribusi dan
     * penanda kelayakan di daftar Inventaris Gudang memakai daftar yang sama persis.
     *
     * @return array<int,string>
     */
    public static function blockedPackagingBarcodes(): array
    {
        return static::withoutGlobalScopes()
            ->sterilePool()
            ->join('sterilization_items', function ($join) {
                $join->on('sterilization_items.sterilization_id', '=', 'instrument_storages.sterilization_id')
                    ->on('sterilization_items.instrument_stock_id', '=', 'instrument_storages.instrument_stock_id')
                    ->whereNull('sterilization_items.deleted_by');
            })
            ->whereNotNull('sterilization_items.packaging_barcode')
            ->where(fn ($q) => $q->whereNull('instrument_storages.expiry_date')
                ->orWhereDate('instrument_storages.expiry_date', '<', now()->toDateString()))
            ->distinct()
            ->pluck('sterilization_items.packaging_barcode')
            ->all();
    }

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
