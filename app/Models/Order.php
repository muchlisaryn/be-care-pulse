<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasAuditColumns, HasAutoCode;

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

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'ORD-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'ORD-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
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
