<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu rincian transaksi yang berpindah dalam sebuah penggabungan anggota.
 *
 * Dicatat per rincian (`transactions`), bukan per kuitansi — alasannya ada di
 * migrasi `create_member_merge_items_table`.
 */
class MemberMergeItem extends Model
{
    use HasAuditColumns;

    protected $table = 'member_merge_items';

    /** Rincian ini BERPINDAH ke anggota tujuan. */
    public const ACTION_MOVED = 'moved';

    /**
     * Rincian ini DINONAKTIFKAN, tidak dipindahkan.
     *
     * Terjadi saat periodenya bentrok: anggota tujuan sudah punya transaksi
     * untuk tarif & periode yang sama, dan index unik `transactions_unik`
     * melarang keduanya berdampingan. Yang dipertahankan milik tujuan.
     */
    public const ACTION_DISABLED = 'disabled';

    protected $fillable = [
        'member_merge_id',
        'transaction_id',
        'action',
        'transaction_header_id',
        'transaction_number',
        'previous_member_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function merge(): BelongsTo
    {
        return $this->belongsTo(MemberMerge::class, 'member_merge_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Pemilik rincian ini SEBELUM dipindahkan — hampir selalu anggota asal
     * penggabungannya, dan hampir selalu sudah nonaktif. Karena itu
     * `withDisabled()`, sama alasannya dengan `MemberMerge::sourceMember()`.
     */
    public function previousMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'previous_member_id')->withDisabled();
    }
}
