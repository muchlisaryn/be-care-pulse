<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\MarksDisabledWhenDeleted;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * `MarksDisabledWhenDeleted` menurunkan kolom `disabled` dari `deleted_by`.
     * Sengaja BUKAN di `$fillable` — lihat alasannya di trait tersebut.
     */
    use HasAuditColumns, MarksDisabledWhenDeleted;

    protected $table = 'transactions';

    protected $fillable = [
        'transaction_header_id',
        'member_id',
        'rate_id',
        'month',
        'year',
        'amount',
        'discount',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'amount' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    /**
     * Kolom periode ikut diserahkan ke JSON sebagai `payment_period`.
     *
     * Bentuk response sengaja tidak berubah setelah kolomnya dipecah: frontend
     * tetap menerima satu field "MM/YYYY" seperti sebelumnya.
     */
    protected $appends = ['payment_period'];

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
     * Periode dalam bentuk "MM/YYYY", atau `null` untuk tarif sekali bayar.
     *
     * Kolomnya dipecah jadi `month` + `year` di database, tapi seluruh
     * antarmuka — API, kuitansi, tabel — memakai satu string gabungan. Merakit
     * ulang di sini menghindari format yang berbeda-beda di tiap pemanggil.
     */
    public function getPaymentPeriodAttribute(): ?string
    {
        if ($this->month === null || $this->year === null) {
            return null;
        }

        return sprintf('%02d/%04d', $this->month, $this->year);
    }

    /**
     * Urutan kronologis, terbaru dulu.
     *
     * Baris tanpa periode (tarif sekali bayar) ikut terkumpul di ujung karena
     * NULL diurutkan paling akhir oleh `ORDER BY ... DESC` di MySQL; `id`
     * dipakai sebagai pemecah seri agar urutannya stabil antar halaman.
     */
    public function scopeUrutPeriodeTerbaru(Builder $query): Builder
    {
        return $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('id');
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
