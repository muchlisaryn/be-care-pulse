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
     * Syaratnya relasi & kolom audit — TIDAK ada kolom `status` yang dibaca:
     *  - `deleted_by` NULL — baris gudang belum dihapus;
     *  - `order_id` NULL — belum diklaim / didistribusikan ke order mana pun;
     *  - `removed_at` NULL — belum diangkat dari rak (baik untuk diantar ke order maupun
     *    ditarik kembali ke produksi, lihat ProductionController::closeStorageForReprocessed).
     *
     * Dulu syarat ketiga ditulis sebagai `status = tersimpan`. Itu bisa menyimpang:
     * penarikan unit ke produksi sempat hanya menulis `status` tanpa `removed_at`, jadi
     * kedua penanda saling bertentangan pada baris yang sama. Kolom audit dipakai karena
     * ditulis sekali tepat saat kejadiannya. Baris warisan sudah dirapikan oleh migration
     * `2026_08_13_000001_backfill_removed_at_on_released_storages`.
     */
    public function scopeSterilePool($query)
    {
        return $query->whereNull($query->qualifyColumn('deleted_by'))
            ->whereNull($query->qualifyColumn('order_id'))
            ->whereNull($query->qualifyColumn('removed_at'));
    }

    /**
     * Unit yang sedang tersimpan di rak DAN masih layak pakai.
     *
     * Dipakai ProductionController untuk mengeluarkan unit ini dari kandidat
     * produksi: stok steril yang sudah jadi tidak boleh ditarik ulang ke
     * produksi, karena baris gudangnya ikut ditutup dan stoknya lenyap dari
     * Gudang Steril tanpa pernah dipinjam.
     *
     * "Masih layak" = punya tanggal kedaluwarsa dan belum lewat. Unit yang
     * SUDAH kedaluwarsa (atau tersimpan tanpa tanggal) sengaja TIDAK ikut
     * dilindungi — justru itulah yang wajib diproses ulang. Kalau ikut
     * dikecualikan, unitnya terjebak permanen: distribusi menolaknya lewat
     * blockedPackagingBarcodes(), dan halaman Kedaluwarsa hanya bisa memantau,
     * tidak bisa menarik unit dari rak.
     *
     * @return array<int,int> instrument_stock_id
     */
    public static function readyStockIds(): array
    {
        return static::withoutGlobalScopes()
            ->sterilePool()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->distinct()
            ->pluck('instrument_stock_id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
