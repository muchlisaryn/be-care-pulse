<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Tahap Packaging pada pipeline CSSD. Identitas batch dipecah dua kolom: `prefix`
 * (PKG / RPK) + `code` (ymd + urutan harian), digabung lewat accessor `full_code`
 * — mis. `PKG26050201`. Dirangkai ke tahap cleaning lewat washing_code
 * (= washing.code).
 */
class Packaging extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'packaging';

    // Status tahap packaging.
    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    /** Pengemasan normal. */
    public const PREFIX_NORMAL = 'PKG';

    /**
     * Pengemasan ULANG — record baru yang dibuat saat unit gagal steril
     * dikembalikan ke tahap packaging. Prefix ditetapkan sekali saat record dibuat
     * dan tidak pernah diubah: kode batch sudah dicetak di label barcode fisik &
     * tercatat di pipeline_events, jadi mengubahnya akan memutus jejak audit.
     * Status gagal/void tetap dibaca dari kolom `disabled`, bukan dari prefix.
     */
    public const PREFIX_REPROCESS = 'RPK';

    protected $attributes = [
        'prefix' => self::PREFIX_NORMAL,
    ];

    protected $fillable = [
        'prefix',
        'code',
        'washing_code',
        'reprocess_of',
        'round',
        'sterilization_id',
        'operator',
        'chemical_indicator',
        'packaging_type_id',
        'packaged_at',
        'expiry_date',
        'note',
        'status',
        'disabled',
        'disabled_at',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'packaged_at' => 'datetime',
        'expiry_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'disabled' => 'boolean',
        'disabled_at' => 'datetime',
    ];

    /**
     * Bagian ANGKA kode batch berikutnya: tahun(2) + bulan(2) + tanggal(2) + urutan
     * HARIAN (2 digit, reset tiap hari), mis. `26050201` lalu `26050202`, dan besok
     * kembali ke `26050301`. Bila melebihi 99 otomatis jadi 3+ digit.
     *
     * Deret nomor berjalan LINTAS PREFIX: `code` wajib unik apa pun prefixnya, jadi
     * pengemasan ulang mengambil nomor berikutnya (PKG26050201 → RPK26050202), bukan
     * mengulang deretnya sendiri. Nomor urut = angka terkecil yang belum dipakai
     * pada tanggal hari ini, sehingga slot yang kosong dipakai ulang.
     */
    protected static function generateUniqueCode($model): string
    {
        $day = now()->format('ymd');

        // Nomor urut yang sedang terpakai hari ini (lintas prefix & lintas scope,
        // agar mencakup record apa pun yang masih menempati index unik `code`).
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
     * Kode batch utuh untuk ditampilkan & dicatat (label, PipelineEvent, kartu):
     * prefix + angka, mis. `PKG26050201` / `RPK26050201`. Kolom `code` sendiri
     * hanya berisi angkanya, jadi jangan tampilkan `code` mentah ke pengguna.
     */
    public function getFullCodeAttribute(): string
    {
        return $this->prefix.$this->code;
    }

    /**
     * Nomor barcode label untuk satu set: prefix + kode packaging + nomor set,
     * DIGABUNG TANPA SPASI (mis. `PKG260719011`). Tampilan preview label tetap
     * memakai tiga segmen berspasi — ini khusus nilai yang disimpan & dipindai.
     */
    public function barcodeNoFor(?int $packageNo): string
    {
        return $this->prefix.$this->code.($packageNo ?? '');
    }

    /** Batch pengemasan asal yang di-void (diisi hanya pada record RPK). */
    public function reprocessOf()
    {
        return $this->belongsTo(self::class, 'reprocess_of');
    }

    /** Ronde pengemasan berikutnya untuk satu batch cleaning (1, 2, 3, ...). */
    public static function nextRound(?string $washingCode): int
    {
        if (! $washingCode) {
            return 1;
        }

        return (int) static::withoutGlobalScopes()
            ->where('washing_code', $washingCode)
            ->max('round') + 1;
    }

    /**
     * Jenis kemasan yang dipilih saat pengemasan — masa simpannya menentukan
     * `expiry_date`. Sengaja mengabaikan global scope `active`: jenis kemasan yang
     * sudah dihapus admin harus tetap terbaca di riwayat & label batch lama.
     */
    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class)->withoutGlobalScope('active');
    }

    /** Tahap cleaning asal (via washing_code). */
    public function washing()
    {
        return $this->belongsTo(OrderWashing::class, 'washing_code', 'code');
    }

    /** Batch sterilisasi (STR) yang menampung packaging ini (banyak PKG → satu STR). */
    public function sterilization()
    {
        return $this->belongsTo(Sterilization::class, 'sterilization_id');
    }

    /** Detail per-unit tahap packaging ini (packaging_item). */
    public function items()
    {
        return $this->hasMany(PackagingItem::class, 'packaging_id');
    }
}
