<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasLegacyAttributes;
use Illuminate\Database\Eloquent\Model;

/** Master tarif iuran & layanan jenazah. */
class Rate extends Model
{
    use HasAuditColumns, HasLegacyAttributes;

    protected $table = 'rates';

    /** Sekali bayar — pungutan/pengeluaran yang tidak berulang tiap periode. */
    public const FEE_TYPE_ONE_TIME = 'one_time';

    /** Berulang — nominalnya dikalikan jumlah periode di halaman Transaksi. */
    public const FEE_TYPE_RECURRING = 'recurring';

    public const FEE_TYPES = [
        self::FEE_TYPE_ONE_TIME,
        self::FEE_TYPE_RECURRING,
    ];

    protected $fillable = ['code', 'category', 'fee_type', 'rate_group', 'group_name', 'name', 'rate_code', 'note', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    protected static array $legacyAttributes = [
        'kode' => 'code',
        'kategori' => 'category',
        'grup_tarif' => 'rate_group',
        'nama_grup' => 'group_name',
        'nama' => 'name',
        'kode_tarif' => 'rate_code',
        'keterangan' => 'note',
        'harga' => 'price',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
