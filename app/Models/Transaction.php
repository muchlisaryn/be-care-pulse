<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Rincian transaksi iuran anggota Nafsul.
 *
 * Berbeda dengan master Nafsul lain, model ini tidak memakai
 * `HasLegacyAttributes`: tabelnya baru dan tidak punya kontrak API lama yang
 * harus dipertahankan, jadi nama kolom dan nama field API sama-sama Inggris.
 */
class Transaction extends Model
{
    use HasAuditColumns;

    protected $table = 'transactions';

    protected $fillable = [
        'transaction_header_id',
        'member_id',
        'rate_id',
        'payment_period',
        'amount',
        'discount',
    ];

    protected $casts = [
        'payment_period' => 'date',
        'amount' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    /**
     * UUID diisi otomatis saat baris dibuat.
     *
     * Dipasang lewat event `creating`, bukan diserahkan ke pemanggil: kalau
     * setiap tempat yang membuat baris harus mengingat mengisinya sendiri,
     * cepat atau lambat ada satu yang lupa dan barisnya gagal disimpan karena
     * kolomnya unik & tidak nullable.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** URL memakai uuid, bukan id yang berurutan dan mudah ditebak. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Jumlah yang harus dibayar setelah potongan.
     *
     * Dihitung, bukan disimpan: kalau ikut disimpan, nilainya bisa melenceng
     * dari `amount` dan `discount` saat salah satunya diubah.
     *
     * Dijaga tidak negatif — diskon yang melebihi nominal seharusnya sudah
     * ditolak validasi, tapi data lama bisa saja lolos.
     */
    public function getTotalAttribute(): string
    {
        return number_format(max(0, (float) $this->amount - (float) $this->discount), 2, '.', '');
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(TransactionHeader::class, 'transaction_header_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }
}
