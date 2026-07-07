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
     * urutan HARIAN (2 digit, reset tiap hari), mis. PRD26070701 lalu PRD26070702,
     * dan besok kembali ke PRD26070801. Bila melebihi 99 otomatis jadi 3+ digit.
     *
     * Nomor urut = angka terkecil yang BELUM dipakai pada tanggal hari ini. Batch
     * yang dibatalkan di tahap cleaning di-hard delete, sehingga slot nomornya
     * kosong kembali dan dipakai ulang oleh produksi berikutnya (mengisi celah,
     * aman dari tabrakan `code` unik walau yang dibatalkan bukan nomor terakhir).
     */
    protected static function generateUniqueCode($model): string
    {
        $prefix = 'PRD'.now()->format('ymd');

        // Nomor urut yang sedang terpakai hari ini (dibaca dari code, lintas scope
        // agar mencakup record apa pun yang masih menempati index unik `code`).
        $used = static::withoutGlobalScopes()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->flip();

        $sequence = 1;
        while ($used->has($sequence)) {
            $sequence++;
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
