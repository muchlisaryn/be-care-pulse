<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Unit fisik yang dikunci ke satu batch produksi (menggantikan order_item pada
 * pipeline pemrosesan). Unit mengalir lewat pipeline via code tahap.
 */
class ProductionItem extends Model
{
    use HasAuditColumns;

    protected $table = 'production_item';

    protected $fillable = [
        'production_id',
        'instrument_stock_id',
        // Snapshot identitas unit saat dikunci ke batch — sengaja disalin dari
        // instrument_stocks.code, instruments.name & instruments.image, bukan
        // dibaca lewat relasi, agar riwayat batch tidak ikut berubah bila master
        // berubah. `image` menyimpan path relatif; URL penuh lewat `image_url`.
        'kode_instrumen',
        'name',
        // Satu kolom foto untuk kedua jenis baris: `paket` menyimpan foto katalog,
        // `satuan` menyimpan foto instrumen.
        'image',
        'source',
        'package_name',
        // Nomor satuan pesanan dalam batch (1, 2, 3, ...): satu nomor per qty.
        // Semua unit dalam satu set berbagi nomor yang sama.
        'package_no',
        'condition_out_id',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['image_url'];

    /**
     * Path publik foto hasil snapshot — foto paket atau instrumen (null bila tak ada).
     * Root-relatif; lihat Instrument::getImageUrlAttribute().
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? '/'.ltrim($this->image, '/') : null;
    }

    /** Batch produksi pemilik unit ini. */
    public function production()
    {
        return $this->belongsTo(Production::class);
    }

    /** Unit stok instrumen yang dikunci. */
    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    /** Kondisi unit saat keluar. */
    public function conditionOut()
    {
        return $this->belongsTo(Condition::class, 'condition_out_id');
    }
}
