<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Satu kali penggabungan anggota — riwayat perpindahan transaksi dari satu
 * anggota ke anggota lain.
 *
 * Tabelnya baru dan tidak punya kontrak API lama berbahasa Indonesia yang harus
 * dipertahankan, jadi nama kolom dan nama field API sama-sama Inggris — sama
 * seperti `Transaction` & `TransactionHeader`.
 */
class MemberMerge extends Model
{
    use HasAuditColumns;

    protected $table = 'member_merges';

    protected $fillable = [
        'source_member_id',
        'target_member_id',
        'header_count',
        'transaction_count',
        'disabled_count',
        'amount',
        'source_disabled',
        'note',
    ];

    protected $casts = [
        'header_count' => 'integer',
        'transaction_count' => 'integer',
        'disabled_count' => 'integer',
        'amount' => 'decimal:2',
        'source_disabled' => 'boolean',
    ];

    /**
     * UUID diisi otomatis saat baris dibuat — kolomnya unik & tidak nullable,
     * jadi tidak boleh bergantung pada pemanggil yang mengingatnya.
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
     * Anggota ASAL. `withDisabled()` WAJIB di sini: anggota asal justru
     * dinonaktifkan oleh penggabungan ini, sehingga tanpa itu relasinya
     * mengembalikan null dan riwayatnya tampil tanpa nama siapa pun.
     */
    public function sourceMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'source_member_id')->withDisabled();
    }

    /**
     * Anggota TUJUAN. Ikut `withDisabled()` karena ia sendiri bisa digabungkan
     * lagi ke anggota ketiga di kemudian hari — dan riwayat lama tetap harus
     * bisa dibaca setelah itu.
     */
    public function targetMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'target_member_id')->withDisabled();
    }

    public function items(): HasMany
    {
        return $this->hasMany(MemberMergeItem::class);
    }
}
