<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail per-unit tahap Packaging. Satu baris per unit fisik dalam sebuah batch
 * packaging. `disabled = true` menandai unit di-void di tahap ini (mis. PKG lama saat
 * unit gagal steril diproses ulang) sehingga mudah dilacak per unit.
 *
 * `barcode_no` = nomor yang tercetak di label kemasan (prefix + kode packaging +
 * nomor set, tanpa spasi). Tidak unik: unit-unit dalam satu set berbagi satu label,
 * jadi berbagi nomor yang sama juga.
 */
class PackagingItem extends Model
{
    use HasAuditColumns;

    protected $table = 'packaging_item';

    protected $fillable = [
        'packaging_id',
        'instrument_stock_id',
        'source',
        'package_name',
        'barcode_no',
        'disabled',
        'disabled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    public function packaging()
    {
        return $this->belongsTo(Packaging::class, 'packaging_id');
    }

    public function instrumentStock()
    {
        return $this->belongsTo(InstrumentStock::class);
    }

    /**
     * Peta `instrument_stock_id` → NOMOR LABEL kemasan untuk siklus pipeline yang
     * batch cleaning-nya ada di `$washingCodes`.
     *
     * Dibatasi per siklus, bukan "label terakhir unit": satu unit fisik bisa lewat
     * pipeline berkali-kali dan tiap siklus punya label sendiri, jadi mengambil
     * label terbaru akan menempelkan label siklus lain pada baris riwayat lama.
     * Label yang sudah di-void (`disabled`) diabaikan.
     *
     * @param  array<int,string>  $washingCodes
     * @return array<int,string>
     */
    public static function barcodeMapByWashingCodes(array $washingCodes): array
    {
        $washingCodes = array_values(array_filter($washingCodes));
        if (empty($washingCodes)) {
            return [];
        }

        // Global scope dimatikan & `deleted_by` di-kualifikasi manual: dengan JOIN,
        // kolom itu ada di kedua tabel (lihat HasAuditColumns yang tidak meng-alias).
        return static::withoutGlobalScopes()
            ->join('packaging', 'packaging.id', '=', 'packaging_item.packaging_id')
            ->whereIn('packaging.washing_code', $washingCodes)
            ->where('packaging_item.disabled', false)
            ->whereNotNull('packaging_item.barcode_no')
            ->whereNull('packaging_item.deleted_by')
            ->whereNull('packaging.deleted_by')
            // Urut id ASC → bila satu unit punya beberapa baris pada siklus yang
            // sama (mis. pengemasan ulang RPK), label TERBARU yang dipakai.
            ->orderBy('packaging_item.id')
            ->pluck('packaging_item.barcode_no', 'packaging_item.instrument_stock_id')
            ->all();
    }

    /**
     * Sama dengan barcodeMapByWashingCodes(), tapi titik masuknya kode batch
     * PRODUKSI — batch cleaning-nya ditelusuri lewat `washing.production_code`.
     *
     * @param  array<int,string>  $productionCodes
     * @return array<int,string>
     */
    public static function barcodeMapByProductionCodes(array $productionCodes): array
    {
        $productionCodes = array_values(array_filter($productionCodes));
        if (empty($productionCodes)) {
            return [];
        }

        return static::barcodeMapByWashingCodes(
            OrderWashing::whereIn('production_code', $productionCodes)->pluck('code')->all()
        );
    }
}
