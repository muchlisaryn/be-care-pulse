<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Catatan pencucian (Cleaning) — tahap mandiri pada pipeline CSSD (tabel: washing,
 * code WSH+ymd+urutan harian, mis. WSH26071901). Tidak menyimpan order_id; keterkaitan ke order hanya di tahap
 * sterilisasi. Dirangkai ke tahap produksi lewat production_code.
 */
class OrderWashing extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'washing';

    // Status proses pencucian.
    public const STATUS_DALAM_PROSES = 'dalam_proses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    // Batch dibatalkan sebelum diproses — tetap disimpan sebagai riwayat.
    public const STATUS_BATAL = 'batal';

    protected $fillable = [
        'code',
        'production_code',
        'washer_machine_id',
        'operator',
        'temperature',
        'washed_at',
        'duration_minutes',
        'detergent_type',
        'alert',
        'alert_message',
        'failure_reason',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'canceled_by',
        'canceled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'washed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'alert' => 'boolean',
    ];

    /**
     * Kode batch cleaning berikutnya: WSH + tahun(2) + bulan(2) + tanggal(2) +
     * urutan HARIAN (2 digit, reset tiap hari), mis. WSH26071901 lalu WSH26071902,
     * dan besok kembali ke WSH26072001. Bila melebihi 99 otomatis jadi 3+ digit.
     * Formatnya sengaja dibuat sama dengan kode produksi (PRD).
     *
     * Nomor urut = angka terkecil yang BELUM dipakai pada tanggal hari ini, sehingga
     * slot nomor yang kosong (record dihapus) dipakai ulang tanpa menabrak index
     * unik `code`. Batch lama dengan format WSH-NNN tidak terpengaruh — prefixnya
     * berbeda, jadi tidak ikut terhitung.
     */
    protected static function generateUniqueCode($model): string
    {
        $prefix = 'WSH'.now()->format('ymd');

        // Nomor urut yang sedang terpakai hari ini (dibaca dari code, lintas scope
        // agar mencakup record apa pun yang masih menempati index unik `code`).
        $used = static::withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->flip();

        $sequence = 1;
        while ($used->has($sequence)) {
            $sequence++;
        }

        return $prefix.str_pad($sequence, 2, '0', STR_PAD_LEFT);
    }

    /** Tahap produksi asal (via production_code). */
    public function production()
    {
        return $this->belongsTo(Production::class, 'production_code', 'code');
    }

    /** Detail per-unit tahap cleaning ini (washing_item). */
    public function items()
    {
        return $this->hasMany(WashingItem::class, 'washing_id');
    }

    /** Mesin washer yang dipakai. */
    public function washerMachine()
    {
        return $this->belongsTo(WasherMachine::class);
    }
}
