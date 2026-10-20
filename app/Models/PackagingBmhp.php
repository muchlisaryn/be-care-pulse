<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class PackagingBmhp extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'packaging_bmhp';

    // Tahap kemasan tidak disimpan sebagai `status`, tetapi dibaca dari kolomnya
    // lewat stage(): `completed_at` kosong = menunggu pengemasan; terisi = siap
    // steril; sudah ada di `sterilization_bmhp_items` = masuk sterilisasi (hasilnya
    // di `result`); `disabled` = di-void (gagal steril).
    public const STAGE_BELUM = 'belum';

    public const STAGE_SIAP = 'siap';

    public const STAGE_STERILISASI = 'sterilisasi';

    public const STAGE_STERIL = 'steril';

    public const STAGE_GAGAL = 'gagal';

    // Alasan kemasan di-void (`disabled_reason.reason`).
    public const DISABLED_GAGAL_STERIL = 'gagal_steril';

    protected $fillable = [
        'production_bmhp_id',
        'prefix',
        'code',
        'packaging_type_id',
        'chemical_indicator',
        'operator',
        'packaged_at',
        'expiry_date',
        'note',
        'completed_by',
        'completed_at',
        'disabled',
        'disabled_at',
        'disabled_reason',
        'disabled_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'packaged_at' => 'datetime',
        'expiry_date' => 'date',
        'completed_at' => 'datetime',
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
        // Rincian void (JSON): reason, packaging, sterilization, replaced_by, by, at.
        'disabled_reason' => 'array',
    ];

    /**
     * Kode unik Packaging BMHP
     */
    protected static function generateUniqueCode($model): string
    {
        $day = now()->format('ymd');

        $used = static::withoutGlobalScopes()
            ->where('code', 'like', $day.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($day)))
            ->flip();

        $sequence = 1;
        while ($used->has($sequence)) {
            $sequence++;
        }

        return $day.str_pad($sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Kode berikutnya (tanpa prefix) — dipakai sebelum kemasan dibuat, karena kode
     * produksi BMHP = BMHP-{prefix}-{kode kemasan ini}.
     */
    public static function nextCode(): string
    {
        return static::generateUniqueCode(new static);
    }

    public function getFullCodeAttribute(): string
    {
        return $this->prefix.$this->code;
    }

    public function barcodeNoFor(?int $packageNo): string
    {
        return $this->prefix.$this->code.($packageNo ?? '');
    }

    public function production()
    {
        return $this->belongsTo(ProductionBmhp::class, 'production_bmhp_id');
    }

    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class, 'packaging_type_id');
    }

    public function items()
    {
        return $this->hasMany(PackagingBmhpItem::class, 'packaging_bmhp_id');
    }

    public function sterilizationItems()
    {
        return $this->hasMany(SterilizationBmhpItem::class, 'packaging_bmhp_id');
    }

    /**
     * Tambah penanda `in_sterilization` & `is_sterilized` (EXISTS) yang dipakai
     * stage() — tanpa memuat baris item sterilisasinya.
     */
    public function scopeWithStageFlags($query)
    {
        return $query->withExists([
            'sterilizationItems as in_sterilization',
            'sterilizationItems as is_sterilized' => fn ($q) => $q->where('result', SterilizationBmhpItem::RESULT_STERIL),
        ]);
    }

    /** Tahap kemasan (STAGE_*) — butuh query memakai withStageFlags(). */
    public function stage(): string
    {
        if ($this->disabled) {
            return self::STAGE_GAGAL;
        }
        if ($this->completed_at === null) {
            return self::STAGE_BELUM;
        }
        if (! $this->in_sterilization) {
            return self::STAGE_SIAP;
        }

        // Hasil gagal selalu disertai void (`disabled`), jadi sisanya steril / menunggu validasi.
        return $this->is_sterilized ? self::STAGE_STERIL : self::STAGE_STERILISASI;
    }
}
