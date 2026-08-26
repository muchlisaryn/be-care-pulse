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
        // Penanda VOID: baris ditarik dari rak untuk dikemas ulang (fitur
        // "Packaging Ulang" di halaman Alat Kedaluwarsa Steril). Barisnya sengaja
        // tidak dihapus supaya riwayat rak & nomor labelnya tetap terbaca.
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'stored_at' => 'datetime',
        'removed_at' => 'datetime',
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
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
     *    ditarik kembali ke produksi, lihat ProductionController::closeStorageForReprocessed);
     *  - `disabled_at` NULL — barisnya belum di-void oleh Packaging Ulang.
     *
     * Syarat `disabled_at` itulah yang menjamin unit kedaluwarsa yang sedang dikemas
     * ulang TIDAK BISA DIPINJAM: karena scope ini dipakai bersama oleh penyusun
     * kandidat distribusi, angka siap-order di form order, `available_sterile_sets`
     * paket, dan daftar Gudang Steril, satu syarat di sini menutup semuanya
     * sekaligus. Secara teknis baris yang di-void selalu ikut berisi `removed_at`,
     * tapi syaratnya ditulis eksplisit supaya jaminannya tidak bergantung pada dua
     * kolom yang selalu diisi berbarengan — persis jenis penyimpangan yang dulu
     * terjadi antara `status` dan `removed_at`.
     *
     * Dulu syarat ketiga ditulis sebagai `status = tersimpan`. Itu bisa menyimpang:
     * penarikan unit ke produksi sempat hanya menulis `status` tanpa `removed_at`, jadi
     * kedua penanda saling bertentangan pada baris yang sama. Kolom audit dipakai karena
     * ditulis sekali tepat saat kejadiannya. Baris warisan sudah dirapikan oleh migration
     * `2026_08_13_000001_backfill_removed_at_on_released_storages`.
     *
     * PERHATIKAN `order_id` NULL di sini. Scope ini menjawab "baris mana yang
     * boleh didistribusikan", jadi baris yang sudah direservasi order memang
     * dibuang. Itu BUKAN jawaban untuk "unit mana yang fisiknya masih di rak" —
     * baris direservasi barangnya jelas masih di rak. Untuk pertanyaan yang
     * kedua pakai `heldInRackStockIds()`, jangan scope ini.
     */
    public function scopeSterilePool($query)
    {
        return $query->whereNull($query->qualifyColumn('deleted_by'))
            ->whereNull($query->qualifyColumn('order_id'))
            ->whereNull($query->qualifyColumn('removed_at'))
            ->whereNull($query->qualifyColumn('disabled_at'));
    }

    /**
     * Baris gudang yang FISIKNYA MASIH DI RAK — belum dihapus, belum diangkat, dan
     * belum di-void. Bedanya dengan `sterilePool()`: reservasi order (`order_id`)
     * TIDAK dipedulikan, karena barang yang sudah dipesan pun masih menempati rak.
     *
     * Dipakai untuk pertanyaan "unit ini fisiknya di mana", bukan "baris ini boleh
     * didistribusikan" — yang kedua tetap `sterilePool()`.
     */
    public function scopeStillInRack($query)
    {
        return $query->whereNull($query->qualifyColumn('deleted_by'))
            ->whereNull($query->qualifyColumn('removed_at'))
            ->whereNull($query->qualifyColumn('disabled_at'));
    }

    /**
     * Unit yang fisiknya MASIH MENEMPATI RAK — TANPA kecuali.
     *
     * Dipakai ProductionController untuk MENGELUARKAN unit ini dari kandidat
     * produksi. Stok steril yang sudah jadi tidak boleh ditarik ulang: petugas
     * hanya memilih jenis + jumlah — bukan unit fisiknya — jadi baris gudangnya
     * akan ditutup diam-diam dan stoknya lenyap dari Gudang Steril tanpa pernah
     * dipinjam.
     *
     * Syaratnya `deleted_by` NULL + `removed_at` NULL saja, TANPA `order_id`
     * NULL — jadi ini BUKAN turunan `sterilePool()`. Baris yang sudah
     * direservasi order barangnya jelas masih di rak, dan justru paling tidak
     * boleh ditarik: statusnya di `instrument_stocks` masih `tersedia` sampai
     * ordernya benar-benar didistribusikan, sehingga tanpa syarat ini ia
     * terlihat sama saja dengan unit bebas dan bisa tertarik ke produksi —
     * membatalkan janji ke pemesan tanpa ada yang tahu.
     *
     * TANGGAL KEDALUWARSA TIDAK IKUT DISARING. Apa pun isi `expiry_date` — masih
     * berlaku, sudah lewat, atau kosong — selama barisnya masih di rak, unitnya
     * tidak boleh tertarik ke produksi. Produksi hanya menerima jenis + jumlah,
     * bukan unit fisiknya, jadi tiap unit rak yang lolos ke sini baris gudangnya
     * ditutup diam-diam oleh ProductionController::closeStorageForReprocessed()
     * tanpa petugas pernah memilihnya: stoknya lenyap dari Gudang Steril, dan
     * bungkus steril yang tersisa di rak berubah jadi set tak lengkap.
     *
     * Dulu unit kedaluwarsa sengaja DIBIARKAN lolos, karena produksi satu-satunya
     * jalan keluarnya dari rak — halaman Kedaluwarsa waktu itu cuma `index` &
     * `summary`, memantau tanpa aksi. Alasan itu sudah gugur: Packaging Ulang
     * (`SterileExpiryController::repackage`) kini menarik label kedaluwarsa dari
     * rak secara eksplisit — petugas memilih barisnya sendiri, satu label utuh
     * sekaligus, dan baris raknya di-void. Jadi unit kedaluwarsa punya pintu
     * keluar yang benar dan tidak perlu lagi menumpang jalur produksi.
     *
     * @return array<int,int> instrument_stock_id
     */
    public static function heldInRackStockIds(): array
    {
        return static::withoutGlobalScopes()
            ->stillInRack()
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

    /**
     * Peta NOMOR LABEL kemasan tiap unit PADA SIKLUS ORDER-nya, di-key
     * `"{order_id}|{instrument_stock_id}"`.
     *
     * Jejaknya: baris gudang steril yang direservasi/dikeluarkan untuk order itu
     * (`instrument_storages.order_id`) → batch sterilisasi baris tsb → nomor label
     * yang dibawa unitnya di batch itu (`sterilization_items.packaging_barcode`).
     *
     * Sengaja per-SIKLUS, bukan "label terakhir unit": satu unit fisik lewat pipeline
     * berkali-kali dan tiap siklus punya labelnya sendiri. Mengambil label terbaru
     * menempelkan label siklus BARU pada order lama — dan karena tampilan
     * mengelompokkan instrumen per nomor label, satu set utuh lalu tampak terpecah
     * jadi dua bungkus begitu sebagian isinya dikemas ulang untuk order berikutnya.
     *
     * @param  array<int,int>  $orderIds
     * @return array<string,string>
     */
    public static function packagingBarcodeMapByOrders(array $orderIds): array
    {
        $orderIds = collect($orderIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($orderIds)) {
            return [];
        }

        $map = [];

        // Global scope dimatikan & `deleted_by` di-kualifikasi manual: dengan JOIN,
        // kolom itu ada di kedua tabel (lihat HasAuditColumns yang tidak meng-alias).
        static::withoutGlobalScopes()
            ->join('sterilization_items', function ($join) {
                $join->on('sterilization_items.sterilization_id', '=', 'instrument_storages.sterilization_id')
                    ->on('sterilization_items.instrument_stock_id', '=', 'instrument_storages.instrument_stock_id')
                    ->whereNull('sterilization_items.deleted_by');
            })
            ->whereIn('instrument_storages.order_id', $orderIds)
            ->whereNull('instrument_storages.deleted_by')
            ->whereNotNull('sterilization_items.packaging_barcode')
            // Urut id ASC → bila satu unit punya beberapa baris gudang untuk order yang
            // sama (mis. dialokasikan ulang sebelum didistribusikan), baris TERAKHIR —
            // yang benar-benar dikeluarkan — yang menang.
            ->orderBy('instrument_storages.id')
            // toBase(): hasilnya baris mentah, bukan model. Alias kolom dari tabel
            // sebelah tidak boleh melewati mapping atribut Eloquent (lihat catatan
            // "SENGAJA TIDAK ADA accessor" di bawah).
            ->toBase()
            ->get([
                'instrument_storages.order_id',
                'instrument_storages.instrument_stock_id',
                'sterilization_items.packaging_barcode',
            ])
            ->each(function ($row) use (&$map) {
                $map[$row->order_id.'|'.$row->instrument_stock_id] = $row->packaging_barcode;
            });

        return $map;
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
