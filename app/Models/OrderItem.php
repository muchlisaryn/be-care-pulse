<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasAuditColumns;

    protected $table = 'order_item';

    protected $fillable = [
        'order_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'condition_out_id',
        'condition_in_id',
        'is_returned',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_returned' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    public function conditionOut()
    {
        return $this->belongsTo(Condition::class, 'condition_out_id');
    }

    public function conditionIn()
    {
        return $this->belongsTo(Condition::class, 'condition_in_id');
    }

    /**
     * Id unit fisik milik sebuah order — penyaring rincian tahap pipeline agar yang
     * tampil hanya instrumen yang benar-benar dipinjam order itu (satu batch bisa
     * berisi unit milik order lain).
     *
     * `null` (bukan array kosong) bila `$orderId` tidak diberikan: pemanggil memakai
     * itu untuk membedakan "tanpa penyaringan" dari "order ini memang tidak punya
     * unit" — yang kedua harus menghasilkan tabel kosong, bukan tabel penuh.
     *
     * Unit yang SUDAH dikembalikan tetap ikut supaya modal Riwayat masih terisi.
     *
     * @return array<int,int>|null
     */
    public static function stockIdsOfOrder($orderId): ?array
    {
        if ($orderId === null || $orderId === '') {
            return null;
        }

        return static::where('order_id', (int) $orderId)
            ->pluck('instrument_stock_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
