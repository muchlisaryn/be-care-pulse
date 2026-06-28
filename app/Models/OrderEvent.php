<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catatan timeline tracking order (append-only). Tidak memakai HasAuditColumns
 * karena event bersifat historis dan tidak boleh ikut tersaring soft-delete.
 */
class OrderEvent extends Model
{
    // Tipe event timeline.
    public const TYPE_DIBUAT = 'dibuat';

    public const TYPE_DITERIMA = 'diterima';

    public const TYPE_DIPINJAM = 'dipinjam';

    public const TYPE_DIKEMBALIKAN = 'dikembalikan';

    public const TYPE_DIPINDAH = 'dipindah';

    public const TYPE_DIBATALKAN = 'dibatalkan';

    // Pipeline pemrosesan CSSD: order diproses → selesai dicuci.
    public const TYPE_DIPROSES = 'diproses';

    public const TYPE_SELESAI_CUCI = 'selesai_cuci';

    public const TYPE_GAGAL_CUCI = 'gagal_cuci';

    // Tahap Sterilisasi: order dimasukkan ke batch sterilisasi.
    public const TYPE_DISTERILKAN = 'disterilkan';

    // Hasil validasi sterilisasi: steril & siap rilis / gagal & wajib re-proses.
    public const TYPE_STERIL = 'steril';

    public const TYPE_GAGAL_STERIL = 'gagal_steril';

    // Tahap Penyimpanan: order tersimpan di gudang steril.
    public const TYPE_DISIMPAN = 'disimpan';

    // Tahap Distribusi: alat steril didistribusikan ke unit / pasien.
    public const TYPE_TERDISTRIBUSI = 'terdistribusi';

    protected $table = 'order_events';

    // Hanya created_at yang dipakai (append-only, tanpa updated_at).
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'code_transaction',
        'type',
        'room_id',
        'actor',
        'borrowed_by',
        'note',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Helper pencatat event timeline. Mengisi created_at otomatis & actor dari
     * user yang login bila tidak diberikan.
     */
    public static function record(string $type, Order $order, array $attributes = []): self
    {
        return static::create([
            'order_id' => $order->id,
            'code_transaction' => $attributes['code_transaction'] ?? $order->code_transaction,
            'type' => $type,
            'room_id' => $attributes['room_id'] ?? $order->room_id,
            'actor' => $attributes['actor'] ?? (auth()->user()?->name),
            'borrowed_by' => $attributes['borrowed_by'] ?? $order->borrowed_by,
            'note' => $attributes['note'] ?? null,
            'created_at' => $attributes['created_at'] ?? now(),
        ]);
    }
}
