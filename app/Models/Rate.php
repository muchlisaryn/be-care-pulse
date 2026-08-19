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

    protected $fillable = ['code', 'category', 'rate_group', 'group_name', 'name', 'rate_code', 'note', 'price'];

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
