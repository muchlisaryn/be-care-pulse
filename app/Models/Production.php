<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Tahap awal pipeline CSSD: Produksi (PRD-NNN). Titik masuk pemrosesan.
 * Tahap berikutnya dirangkai lewat code (washing.production_code = production.code).
 */
class Production extends Model
{
    use HasAuditColumns, HasAutoCode;

    protected $table = 'production';

    // Asal batch produksi.
    public const SOURCE_INTERNAL = 'internal';       // produksi stok CSSD sendiri
    public const SOURCE_REPROCESSING = 'reprocessing'; // dari order peminjaman yang dikembalikan

    // Status tahap produksi.
    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    protected $fillable = [
        'code',
        'source',
        'reference_code',
        'note',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Kode batch produksi berikutnya: PRD + tahun(2) + bulan(2) + tanggal(2) +
     * urutan harian(2 digit), mis. PRD26070201 (2026-07-02, produksi ke-1).
     * Counter di-reset tiap hari.
     */
    protected static function generateUniqueCode($model): string
    {
        $prefix = 'PRD'.now()->format('ymd');

        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/(\d{2})$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.str_pad($sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Nama batch produksi: gabungan nama paket (untuk unit paket) & nama instrumen
     * (untuk unit satuan), unik. Fallback "Produksi CSSD" bila belum ada unit.
     */
    public function displayName(): string
    {
        $this->loadMissing('items.instrumentStock.instrument');

        $names = $this->items
            ->map(fn ($i) => $i->source === 'paket'
                ? ($i->package_name ?? 'Paket')
                : ($i->instrumentStock?->instrument?->name ?? 'Instrumen'))
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? 'Produksi CSSD' : $names->implode(', ');
    }

    /** Unit fisik yang dikunci ke batch produksi ini. */
    public function items()
    {
        return $this->hasMany(ProductionItem::class);
    }

    /** Tahap cleaning yang mengalir dari produksi ini (via production_code). */
    public function washings()
    {
        return $this->hasMany(OrderWashing::class, 'production_code', 'code');
    }
}
