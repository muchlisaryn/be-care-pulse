<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasAuditColumns {
        delete as protected auditDelete;
        restore as protected auditRestore;
    }
    use HasAutoCode;

    // "order" adalah reserved keyword SQL — wajib di-set eksplisit.
    protected $table = 'order';

    // Status order/peminjaman (PRD §4.6)
    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_DIPINJAM = 'dipinjam';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    // Pipeline pemrosesan CSSD (reprocessing): order masuk → diproses (Proses) →
    // pencucian (Cleaning) → pengemasan → selesai (siap sterilisasi).
    public const STATUS_PENCUCIAN = 'pencucian';

    public const STATUS_PENGEMASAN = 'pengemasan';

    public const STATUS_SELESAI = 'selesai';

    // Tahap Sterilisasi: order yang sudah dimasukkan ke batch sterilisasi.
    public const STATUS_STERILISASI = 'sterilisasi';

    // Hasil sterilisasi tervalidasi: steril & siap rilis.
    public const STATUS_STERIL = 'steril';

    // Tahap Penyimpanan: seluruh unit order tersimpan di gudang steril.
    public const STATUS_DIGUDANG = 'digudang';

    public const STATUSES = [
        self::STATUS_DIAJUKAN,
        self::STATUS_DIPINJAM,
        self::STATUS_DIKEMBALIKAN,
        self::STATUS_DIBATALKAN,
        self::STATUS_PENCUCIAN,
        self::STATUS_PENGEMASAN,
        self::STATUS_SELESAI,
        self::STATUS_STERILISASI,
        self::STATUS_STERIL,
        self::STATUS_DIGUDANG,
    ];

    protected $fillable = [
        'room_id',
        'user_id',
        'code_transaction',
        'borrowed_by',
        'order_date',
        'order_time',
        'return_plan_date',
        'return_actual_date',
        'returned_by',
        'medical_record_no',
        'patient_name',
        'distributed_to',
        'distributed_at',
        'status',
        'note',
        'canceled_at',
        'canceled_by',
        'processed_at',
        'processed_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'return_plan_date' => 'date',
        'return_actual_date' => 'date',
        'canceled_at' => 'datetime',
        'processed_at' => 'datetime',
        'distributed_at' => 'datetime',
    ];

    /**
     * Status order PEMINJAMAN yang diturunkan dari JEJAK, bukan dibaca dari kolom
     * `status`. Kolom itu ditulis ulang di banyak titik dan ada jalur yang melewatinya
     * sama sekali — pinjam-alih memindahkan `order_item` ke order baru tanpa menyentuh
     * status order sumber, sehingga order yang sudah tidak memegang apa pun tetap
     * tampil "Distributed" selamanya.
     *
     * Urutannya dari yang paling menentukan:
     *  1. `canceled_at` terisi                → dibatalkan;
     *  2. `return_actual_date` terisi         → dikembalikan;
     *  3. punya unit & tidak ada yang belum kembali (`order_item.is_returned`)
     *                                         → dikembalikan;
     *  4. sudah pernah keluar (`distributed_at`) tapi kini TIDAK memegang unit satu pun
     *     → dikembalikan. Ini kasus order sumber pinjam-alih: unitnya dioper, bukan
     *       ditandai kembali, jadi barisnya habis tanpa meninggalkan jejak lain;
     *  5. `distributed_at` terisi             → dipinjam;
     *  6. `processed_at` terisi               → digudang (diterima CSSD, siap distribusi);
     *  7. selain itu                          → diajukan.
     *
     * Butuh `items_count` & `unreturned_items_count` sudah termuat — pakai
     * `withDerivedStatusCounts()` supaya tidak jadi query per baris.
     */
    public function deriveStatus(): string
    {
        if ($this->canceled_at !== null) {
            return self::STATUS_DIBATALKAN;
        }

        $total = (int) ($this->items_count ?? 0);
        $belumKembali = (int) ($this->unreturned_items_count ?? 0);

        if ($this->return_actual_date !== null
            || ($total > 0 && $belumKembali === 0)
            || ($total === 0 && $this->distributed_at !== null)) {
            return self::STATUS_DIKEMBALIKAN;
        }

        if ($this->distributed_at !== null) {
            return self::STATUS_DIPINJAM;
        }

        if ($this->processed_at !== null) {
            return self::STATUS_DIGUDANG;
        }

        return self::STATUS_DIAJUKAN;
    }

    /** Muat dua angka yang dibutuhkan deriveStatus() dalam satu query. */
    public function scopeWithDerivedStatusCounts($query)
    {
        return $query->withCount([
            'items',
            'items as unreturned_items_count' => fn ($q) => $q->where('is_returned', false),
        ]);
    }

    /**
     * Saring berdasarkan status TURUNAN — aturannya wajib cerminan deriveStatus(),
     * kalau tidak filter di layar akan menyaring dengan dasar berbeda dari label yang
     * ditampilkannya sendiri. Status di luar alur peminjaman (pipeline produksi) tidak
     * punya turunan, jadi tetap jatuh ke kolom `status`.
     */
    public function scopeWhereDerivedStatus($query, string $status)
    {
        $total = '(select count(*) from order_item oi where oi.order_id = `order`.id and oi.deleted_by is null)';
        $belum = '(select count(*) from order_item oi where oi.order_id = `order`.id and oi.deleted_by is null and oi.is_returned = 0)';

        $sudahKembali = fn ($q) => $q->where(fn ($w) => $w
            ->whereNotNull('return_actual_date')
            ->orWhereRaw("({$total} > 0 and {$belum} = 0)")
            // Order sumber pinjam-alih: unitnya habis dioper, bukan ditandai kembali.
            ->orWhereRaw("({$total} = 0 and distributed_at is not null)"));

        $belumBatal = fn ($q) => $q->whereNull('canceled_at');

        return match ($status) {
            self::STATUS_DIBATALKAN => $query->whereNotNull('canceled_at'),
            self::STATUS_DIKEMBALIKAN => $query->where($belumBatal)->where($sudahKembali),
            self::STATUS_DIPINJAM => $query->where($belumBatal)->whereNot($sudahKembali)
                ->whereNotNull('distributed_at'),
            self::STATUS_DIGUDANG => $query->where($belumBatal)->whereNot($sudahKembali)
                ->whereNull('distributed_at')->whereNotNull('processed_at'),
            self::STATUS_DIAJUKAN => $query->where($belumBatal)->whereNot($sudahKembali)
                ->whereNull('distributed_at')->whereNull('processed_at'),
            default => $query->where('status', $status),
        };
    }

    /** Awalan kode order yang sudah dihapus — sengaja tidak cocok dengan pola `ORD-%`. */
    private const VOID_CODE_PREFIX = 'VOID-';

    protected static function generateUniqueCode($model): string
    {
        // Order yang sudah dihapus kodenya sudah di-void (lihat delete()), jadi tidak
        // ikut terhitung di sini — nomornya kembali bebas untuk order berikutnya.
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'ORD-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'ORD-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Soft delete + lepas nomor urut ORD dan INV-nya agar bisa dipakai ulang order
     * berikutnya. Kode lama tetap disimpan (dengan awalan VOID-) sebagai jejak, bukan
     * dikosongkan, karena kolom `code` unique dan riwayat (order_event /
     * instrument_stock_log) menyimpan kode ini sebagai teks.
     *
     * Catatan pinjam-alih: beberapa order bisa berbagi `code_transaction` yang sama.
     * Melepas milik order ini tidak membebaskan nomor INV selama order lain dalam
     * rantai yang sama masih memakainya (lihat OrderController::generateTransactionCode).
     */
    public function delete(): ?bool
    {
        $this->code = $this->voidCode($this->code);
        $this->code_transaction = $this->voidCode($this->code_transaction);

        return $this->auditDelete();
    }

    /** Beri awalan VOID- pada sebuah kode agar nomornya lepas. Aman dipanggil berulang. */
    private function voidCode(?string $code): ?string
    {
        if (! $code || str_starts_with($code, self::VOID_CODE_PREFIX)) {
            return $code;
        }

        return self::VOID_CODE_PREFIX.$code.'-'.$this->id;
    }

    /**
     * Restore + pulihkan kode ORD dan INV aslinya. Bila nomor lamanya sudah dipakai
     * order lain selama order ini terhapus, order ini dapat kode ORD baru dan kode INV
     * dikosongkan — nomor batch akan dibangkitkan ulang saat order diproses lagi.
     */
    public function restore(): bool
    {
        if ($original = $this->unvoidCode($this->code)) {
            $this->code = $this->codeIsTaken('code', $original)
                ? static::generateUniqueCode($this)
                : $original;
        }

        if ($original = $this->unvoidCode($this->code_transaction)) {
            $this->code_transaction = $this->codeIsTaken('code_transaction', $original)
                ? null
                : $original;
        }

        return $this->auditRestore();
    }

    /** Kembalikan kode asli dari kode yang sudah di-void, atau null bila bukan kode void. */
    private function unvoidCode(?string $code): ?string
    {
        $pattern = '/^'.preg_quote(self::VOID_CODE_PREFIX, '/').'(.+)-\d+$/';

        return $code && preg_match($pattern, $code, $m) ? $m[1] : null;
    }

    private function codeIsTaken(string $column, string $code): bool
    {
        return static::withoutGlobalScopes()
            ->where($column, $code)
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Baris permintaan (jumlah) — sumber untuk generate order_item saat diterima. */
    public function requestItems()
    {
        return $this->hasMany(OrderRequestItem::class);
    }

    /** Event timeline tracking order (dibuat/diterima/dipindah/dikembalikan). */
    public function events()
    {
        return $this->hasMany(OrderEvent::class);
    }

    /** Permintaan pinjam-alih di mana order ini menjadi sumber unit. */
    public function transfers()
    {
        return $this->hasMany(OrderTransfer::class, 'from_order_id');
    }

    /** Batch sterilisasi yang dibuat dari order ini (pipeline tab Sterilization). */
    public function sterilizations()
    {
        return $this->hasMany(Sterilization::class);
    }

    /** Penempatan unit order di rak gudang steril (Tahap 5 — Storage). */
    public function storages()
    {
        return $this->hasMany(InstrumentStorage::class);
    }
}
