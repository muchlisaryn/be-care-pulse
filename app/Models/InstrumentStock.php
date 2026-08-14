<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class InstrumentStock extends Model
{
    use HasAuditColumns, HasAutoCode;

    /**
     * Metadata sementara (konteks + referensi) untuk pencatatan log saat status berubah.
     * Di-set sebelum save oleh controller, mis. ['context' => 'sterilization', 'reference' => 'STR-001'].
     */
    public ?array $logMeta = null;

    // Status unit instrumen (PRD F6 - monitoring & tracking)
    public const STATUS_TERSEDIA = 'tersedia';

    public const STATUS_DIPINJAM = 'dipinjam';

    public const STATUS_STERILISASI = 'sterilisasi';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    public const STATUSES = [
        self::STATUS_TERSEDIA,
        self::STATUS_DIPINJAM,
        self::STATUS_STERILISASI,
        self::STATUS_DIKEMBALIKAN,
    ];

    /**
     * Tahap pipeline AKTUAL (lebih rinci dari `status` yang hanya enum kasar).
     * Dipersist di kolom `stage` agar tracking mudah (langsung terbaca, tak perlu
     * dihitung ulang). Null = unit tersedia / tidak sedang di pipeline.
     */
    public const STAGE_LABELS = [
        'produksi' => 'Dalam Produksi',
        'pencucian' => 'Pencucian',
        'pengemasan' => 'Pengemasan',
        'sterilisasi' => 'Sterilisasi',
        'menunggu_disimpan' => 'Menunggu Disimpan di Rak',
        'disimpan' => 'Disimpan di Rak',
        'kedaluwarsa' => 'Kedaluwarsa',
        'dipinjam' => 'Dipinjam',
        // Nilai warisan: sejak unit yang sudah dikembalikan kembali terhitung sebagai
        // stok tersedia, tahap ini tidak dihasilkan lagi — tetap dipetakan agar baris
        // lama yang kolom `stage`-nya masih berisi ini tetap punya label.
        'dikembalikan' => 'Dikembalikan',
    ];

    protected $fillable = [
        'instrument_id',
        'condition_id',
        'status',
        'stage',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // Catat status awal saat unit dibuat.
        static::created(function (self $stock) {
            $stock->recordStatusLog(null, $stock->status, $stock->logMeta['context'] ?? 'create');
        });

        // Catat setiap perubahan status unit ke riwayat.
        static::updated(function (self $stock) {
            if ($stock->wasChanged('status')) {
                $stock->recordStatusLog(
                    $stock->getOriginal('status'),
                    $stock->status,
                    $stock->logMeta['context'] ?? 'manual'
                );
            }
        });
    }

    /**
     * Ubah status banyak unit sekaligus sambil mencatat riwayat per unit.
     * Pakai ini (bukan ->whereIn()->update()) agar event log & audit tetap berjalan.
     *
     * @param  iterable<int>  $ids
     * @param  array{context?: string, reference?: string, note?: string}  $meta
     */
    public static function transitionMany(iterable $ids, string $to, array $meta = []): void
    {
        $ids = collect($ids)->filter()->unique()->values();

        static::whereIn('id', $ids)->get()->each(function (self $stock) use ($to, $meta) {
            $stock->logMeta = $meta;
            $stock->update(['status' => $to]);
        });

        // Perbarui tahap pipeline (kolom `stage`) mengikuti perubahan status.
        static::syncStages($ids);
    }

    /**
     * Hitung tahap pipeline aktual untuk sekumpulan unit (read-only, ter-batch).
     * Dibaca dari tabel tiap tahap (produksi/washing/packaging/sterilisasi/storage/
     * order) sebagai sumber kebenaran.
     *
     * @param  iterable<int>  $ids
     * @return array<int,array{stage:?string,label:?string}> di-key oleh instrument_stock_id
     */
    public static function computeStages(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $stocks = static::withoutGlobalScopes()->whereIn('id', $ids)->get(['id']);

        $result = [];
        foreach ($stocks as $s) {
            $result[$s->id] = ['stage' => null, 'label' => null];
        }

        // Yang perlu dicari tahapnya = unit yang TIDAK terhitung sisa stok. Dasarnya
        // scope availableStock (jejak relasi + kolom audit), bukan `status !== tersedia`:
        // kalau kolom status tertinggal, unit yang jelas-jelas sedang di pipeline akan
        // dilewati di sini lalu tampil ber-badge "Tersedia" — bertentangan dengan angka
        // "Dipakai/Proses" di layar yang sama.
        $availableIds = static::whereIn('id', $ids)->availableStock()->pluck('id')->flip();
        $active = $stocks->filter(fn ($s) => ! $availableIds->has($s->id));
        if ($active->isEmpty()) {
            return $result;
        }

        $activeIds = $active->pluck('id');

        // Peminjaman aktif (belum dikembalikan).
        $borrowed = OrderItem::whereIn('instrument_stock_id', $activeIds)
            ->where('is_returned', false)
            ->pluck('instrument_stock_id')->unique()->flip();

        // Baris gudang TERBARU tiap unit. Keadaannya dibaca dari kolom relasi & tanggal,
        // bukan dari `instrument_storages.status`:
        //  - `order_id` NULL  → masih di rak (belum diklaim order mana pun);
        //  - `expiry_date`    → masih layak atau sudah kedaluwarsa;
        //  - `order_id` terisi → sudah keluar rak untuk sebuah order.
        $storageIds = InstrumentStorage::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $activeIds)->groupBy('instrument_stock_id')->pluck('id');
        $latestStorage = InstrumentStorage::whereIn('id', $storageIds)
            ->get(['instrument_stock_id', 'order_id', 'removed_at', 'expiry_date'])
            ->keyBy('instrument_stock_id');

        $today = now()->toDateString();
        // Kedaluwarsa dinilai dari tanggalnya saja — di rak maupun sudah keluar. Ini
        // sebab yang paling perlu diketahui petugas (barangnya harus disterilkan ulang),
        // jadi disebut langsung alih-alih disamarkan jadi keterangan umum.
        $expired = $latestStorage->filter(fn ($st) => $st->expiry_date === null
            || $st->expiry_date->toDateString() < $today);
        // Masih di rak & tanggalnya berlaku.
        $stored = $latestStorage->filter(fn ($st) => $st->order_id === null
            && $st->removed_at === null
            && ! $expired->has($st->instrument_stock_id));

        // Batch steril TERBARU tiap unit.
        $sterItemIds = SterilizationItem::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $activeIds)->groupBy('instrument_stock_id')->pluck('id');
        $sterLatest = SterilizationItem::with('sterilization')->whereIn('id', $sterItemIds)->get();

        // Sterilisasi aktif (batch steril terbaru berstatus `diproses`).
        $sterActive = $sterLatest
            ->filter(fn ($it) => $it->sterilization && $it->sterilization->status === Sterilization::STATUS_DIPROSES)
            ->pluck('instrument_stock_id')->flip();

        // Batch steril terbaru sudah `selesai` tapi unitnya belum dibuatkan baris gudang:
        // barangnya steril dan sedang mengantre ditaruh di rak (tab "Perlu Disimpan").
        // Tanpa tahap ini unit tsb jatuh ke cabang terakhir dan salah berlabel.
        $sterDone = $sterLatest
            ->filter(fn ($it) => $it->sterilization && $it->sterilization->status === Sterilization::STATUS_SELESAI);
        $storedPairs = $sterDone->isEmpty()
            ? collect()
            : InstrumentStorage::whereIn('instrument_stock_id', $sterDone->pluck('instrument_stock_id'))
                ->get(['instrument_stock_id', 'sterilization_id'])
                ->map(fn ($r) => $r->sterilization_id.'|'.$r->instrument_stock_id)
                ->flip();
        $awaitingStorage = $sterDone
            ->reject(fn ($it) => $storedPairs->has($it->sterilization_id.'|'.$it->instrument_stock_id))
            ->pluck('instrument_stock_id')->flip();

        // Kode produksi TERBARU tiap unit → cek washing/packaging aktif.
        $prodItemIds = ProductionItem::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $activeIds)->groupBy('instrument_stock_id')->pluck('id');
        $prodCodeByStock = ProductionItem::with('production')
            ->whereIn('id', $prodItemIds)->get()
            ->mapWithKeys(fn ($pi) => [$pi->instrument_stock_id => $pi->production?->code])
            ->filter();
        $codes = $prodCodeByStock->values()->unique()->values();

        $washings = OrderWashing::whereIn('production_code', $codes)->get();
        $washActiveProd = $washings->where('status', OrderWashing::STATUS_DALAM_PROSES)
            ->pluck('production_code')->unique()->flip();
        $washProdByCode = $washings->mapWithKeys(fn ($w) => [$w->code => $w->production_code]);
        $packActiveProd = Packaging::whereIn('washing_code', $washings->pluck('code'))
            ->where('status', Packaging::STATUS_DIPROSES)
            ->where('disabled', false)
            ->pluck('washing_code')->unique()
            ->map(fn ($wc) => $washProdByCode[$wc] ?? null)->filter()->unique()->flip();

        // Cleaning selesai tapi record packaging belum dibuat (antrean menunggu
        // inspeksi) — unitnya tetap terhitung ada di tahap pengemasan.
        $packPendingProd = $washings->where('status', OrderWashing::STATUS_SELESAI)
            ->whereNotIn('code', Packaging::whereIn('washing_code', $washings->pluck('code'))->pluck('washing_code'))
            ->pluck('production_code')->unique()->flip();

        foreach ($active as $s) {
            $sid = $s->id;
            $prodCode = $prodCodeByStock[$sid] ?? null;

            // Urutannya = dari keadaan paling menentukan ke paling umum. Tidak ada satu
            // pun cabang yang membaca `instrument_stocks.status`.
            $stage = match (true) {
                $borrowed->has($sid) => 'dipinjam',
                $sterActive->has($sid) => 'sterilisasi',
                $awaitingStorage->has($sid) => 'menunggu_disimpan',
                $prodCode && ($packActiveProd->has($prodCode) || $packPendingProd->has($prodCode)) => 'pengemasan',
                $prodCode && $washActiveProd->has($prodCode) => 'pencucian',
                $expired->has($sid) => 'kedaluwarsa',
                $stored->has($sid) => 'disimpan',
                // Sisanya: sudah masuk batch produksi tapi belum sampai ke tahap mana pun
                // yang punya penanda sendiri. Unit yang sudah dikembalikan TIDAK jatuh ke
                // sini — siklusnya sudah tuntas sehingga ia terhitung tersedia (lihat
                // scopeAvailableStock) dan tidak pernah ikut dihitung tahapnya.
                default => 'produksi',
            };

            $result[$sid] = ['stage' => $stage, 'label' => self::STAGE_LABELS[$stage] ?? null];
        }

        return $result;
    }

    /**
     * Hitung ulang & PERSIST kolom `stage` untuk sekumpulan unit. Pakai bulk update
     * (tanpa event model) agar tidak memicu log status. Dipanggil di titik-titik
     * transisi pipeline agar kolom selalu mutakhir.
     *
     * @param  iterable<int>  $ids
     */
    public static function syncStages(iterable $ids): void
    {
        $stages = static::computeStages($ids);
        if (empty($stages)) {
            return;
        }

        // Kelompokkan per nilai stage lalu update massal.
        $byStage = [];
        foreach ($stages as $id => $info) {
            $byStage[$info['stage'] ?? '__null__'][] = $id;
        }

        foreach ($byStage as $stage => $groupIds) {
            static::withoutGlobalScopes()->whereIn('id', $groupIds)->update([
                'stage' => $stage === '__null__' ? null : $stage,
            ]);
        }
    }

    private function recordStatusLog(?string $from, string $to, string $context): void
    {
        InstrumentStockLog::create([
            'instrument_stock_id' => $this->id,
            'from_status' => $from,
            'to_status' => $to,
            'context' => $context,
            'reference_code' => $this->logMeta['reference'] ?? null,
            'note' => $this->logMeta['note'] ?? null,
            'created_by' => auth()->user()?->name,
        ]);
    }

    protected static function generateUniqueCode($model): string
    {
        $instrument = Instrument::withoutGlobalScopes()->find($model->instrument_id);
        $prefix = $instrument?->code ?? 'UNKN';

        $maxCode = static::withoutGlobalScopes()
            ->where('instrument_id', $model->instrument_id)
            ->where('code', 'like', $prefix.'-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.'-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Unit yang TIDAK sedang dipegang order berjalan — dibaca dari JEJAK, bukan dari
     * kolom `status`.
     *
     * Kolom `instrument_stocks.status` ditulis ulang di banyak titik sepanjang alur
     * CSSD dan bisa tertinggal bila satu langkah lupa memperbaruinya; begitu itu
     * terjadi, angka "sisa stok" ikut salah tanpa ada yang tahu. Jejak di bawah hanya
     * ditulis sekali tepat saat kejadiannya:
     *  - `order_item.is_returned` = false → unitnya masih di tangan peminjam. Dibaca
     *    per unit, bukan dari header order, karena pengembalian boleh dicicil;
     *  - `order.canceled_at` / `deleted_by` terisi → ordernya batal, unitnya bebas.
     *
     * Aturan ini WAJIB sama dengan syarat "tidak sedang dipegang order berjalan" pada
     * OrderController::distributionCandidates() — begitu keduanya berbeda, halaman
     * katalog menjanjikan stok yang ditolak sendiri saat mau didistribusikan.
     */
    public function scopeNotHeldByActiveOrder($query)
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')
            ->from('order_item')
            ->join('order', 'order.id', '=', 'order_item.order_id')
            ->whereColumn('order_item.instrument_stock_id', 'instrument_stocks.id')
            ->where('order_item.is_returned', false)
            ->whereNull('order_item.deleted_by')
            ->whereNull('order.deleted_by')
            ->whereNull('order.canceled_at'));
    }

    /**
     * SISA STOK: unit yang benar-benar masih bisa dipakai.
     *
     * Tidak satu pun kolom `status` dibaca di sini — semuanya ditentukan dari ada/
     * tidaknya baris relasi, kolom FK yang null/tidak, dan kolom audit:
     *
     *  1. tidak dipegang order berjalan — scopeNotHeldByActiveOrder (`order_item.is_returned`
     *     + `order.canceled_at`/`deleted_by`);
     *  2. tidak tercatat di GUDANG STERIL — tidak ada baris `instrument_storages` yang
     *     masuk `sterilePool()` (belum dihapus, `order_id` NULL, `removed_at` NULL).
     *     Unit yang ada di rak sedang disimpan, bukan stok bebas: ia sudah punya tempat
     *     sendiri di tab Inventaris Gudang Steril dan tidak boleh diubah/dihapus dari
     *     master. Syaratnya sengaja dibaca dari baris yang SAMA dengan yang menyusun tab
     *     itu — begitu keduanya berbeda, satu unit bisa muncul di dua tempat sekaligus;
     *  3. tidak sedang di TENGAH siklus produksi CSSD. Siklus dianggap masih berjalan
     *     selama baris `production_item` TERAKHIR unit belum menghasilkan baris gudang —
     *     mencakup pencucian, pengemasan, sterilisasi, dan batch steril yang sudah selesai
     *     tapi unitnya masih mengantre ditaruh di rak (tab "Perlu Disimpan"). Begitu baris
     *     gudangnya ada, siklus itu tuntas: unitnya entah masih di rak (tersaring syarat 2),
     *     sedang dipinjam (syarat 1), atau SUDAH DIKEMBALIKAN — dan yang terakhir memang
     *     kembali jadi stok bebas. Dulu syarat ini berbunyi "belum pernah tersentuh
     *     produksi", sehingga unit yang sudah dikembalikan peminjam tidak pernah terhitung
     *     tersedia lagi seumur hidupnya.
     *
     * Ketiganya syarat DAN, dan tidak satu pun membaca kolom `status`.
     */
    public function scopeAvailableStock($query)
    {
        return $query->notHeldByActiveOrder()
            // Tidak sedang tersimpan di rak gudang steril.
            ->whereNotExists(fn ($st) => $st->selectRaw('1')
                ->from('instrument_storages')
                ->whereColumn('instrument_storages.instrument_stock_id', 'instrument_stocks.id')
                ->whereNull('instrument_storages.deleted_by')
                ->whereNull('instrument_storages.order_id')
                ->whereNull('instrument_storages.removed_at'))
            // Siklus produksi TERAKHIR unit belum tuntas (belum ada baris gudangnya).
            ->whereNotExists(fn ($p) => $p->selectRaw('1')
                ->from('production_item')
                ->whereColumn('production_item.instrument_stock_id', 'instrument_stocks.id')
                ->whereNull('production_item.deleted_by')
                // Hanya siklus TERAKHIR yang menentukan; siklus lama sudah lewat.
                ->whereRaw('production_item.id = (select max(pi_last.id) from production_item pi_last'
                    .' where pi_last.instrument_stock_id = instrument_stocks.id'
                    .' and pi_last.deleted_by is null)')
                ->whereNotExists(fn ($g) => $g->selectRaw('1')
                    ->from('instrument_storages')
                    ->whereColumn('instrument_storages.production_item_id', 'production_item.id')
                    ->whereNull('instrument_storages.deleted_by')));
    }

    public function instrument()
    {
        return $this->belongsTo(Instrument::class);
    }

    public function condition()
    {
        return $this->belongsTo(Condition::class);
    }

    public function logs()
    {
        return $this->hasMany(InstrumentStockLog::class);
    }
}
