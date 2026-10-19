<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Master BMHP (Bahan Medis Habis Pakai). Kodenya diketik manual oleh admin —
 * tidak dibuat otomatis oleh sistem. Satuannya merujuk master satuan (`unit_id`).
 */
class Bmhp extends Model
{
    use HasAuditColumns;

    protected $table = 'mst_bmhp';

    protected $fillable = [
        'code',
        'name',
        'unit_id',
        'stock_qty',
        'price',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        // Desimal di database (12,2); dikirim sebagai angka, bukan string.
        'stock_qty' => 'float',
        'price' => 'float',
    ];

    // Nama satuan selalu ikut dikirim sebagai `unit` (teks), seperti sebelum
    // satuannya dipindah ke master — pemakai API tidak perlu membongkar relasinya.
    protected $with = ['unitMaster'];

    protected $appends = ['unit'];

    protected $hidden = ['unitMaster'];

    /**
     * Satuan BMHP ini. Satuan yang sudah dihapus dari master tetap terbaca, agar
     * BMHP lama tidak mendadak kehilangan satuannya.
     */
    public function unitMaster()
    {
        return $this->belongsTo(Unit::class, 'unit_id')->withTrashed();
    }

    public function getUnitAttribute(): ?string
    {
        return $this->unitMaster?->name;
    }
}
