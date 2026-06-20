<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

class InstrumentStock extends Model
{
    use HasAuditColumns, HasAutoCode;

    /**
     * Metadata sementara (konteks + referensi) untuk pencatatan log saat status berubah.
     * Di-set sebelum save oleh controller, mis. ['context' => 'sterilization', 'reference' => 'STR-001'].
     */
    public ?array $logMeta = null;

    // Status unit instrumen (PRD F6 - monitoring & tracking)
    public const STATUS_TERSEDIA = 'tersedia';

    public const STATUS_DIPINJAM = 'dipinjam';

    public const STATUS_STERILISASI = 'sterilisasi';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    public const STATUSES = [
        self::STATUS_TERSEDIA,
        self::STATUS_DIPINJAM,
        self::STATUS_STERILISASI,
        self::STATUS_DIKEMBALIKAN,
    ];

    protected $fillable = [
        'instrument_id',
        'condition_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // Catat status awal saat unit dibuat.
        static::created(function (self $stock) {
            $stock->recordStatusLog(null, $stock->status, $stock->logMeta['context'] ?? 'create');
        });

        // Catat setiap perubahan status unit ke riwayat.
        static::updated(function (self $stock) {
            if ($stock->wasChanged('status')) {
                $stock->recordStatusLog(
                    $stock->getOriginal('status'),
                    $stock->status,
                    $stock->logMeta['context'] ?? 'manual'
                );
            }
        });
    }

    /**
     * Ubah status banyak unit sekaligus sambil mencatat riwayat per unit.
     * Pakai ini (bukan ->whereIn()->update()) agar event log & audit tetap berjalan.
     *
     * @param  iterable<int>  $ids
     * @param  array{context?: string, reference?: string, note?: string}  $meta
     */
    public static function transitionMany(iterable $ids, string $to, array $meta = []): void
    {
        static::whereIn('id', $ids)->get()->each(function (self $stock) use ($to, $meta) {
            $stock->logMeta = $meta;
            $stock->update(['status' => $to]);
        });
    }

    private function recordStatusLog(?string $from, string $to, string $context): void
    {
        InstrumentStockLog::create([
            'instrument_stock_id' => $this->id,
            'from_status' => $from,
            'to_status' => $to,
            'context' => $context,
            'reference_code' => $this->logMeta['reference'] ?? null,
            'note' => $this->logMeta['note'] ?? null,
            'created_by' => auth()->user()?->name,
        ]);
    }

    protected static function generateUniqueCode($model): string
    {
        $instrument = Instrument::withoutGlobalScopes()->find($model->instrument_id);
        $prefix = $instrument?->code ?? 'UNKN';

        $maxCode = static::withoutGlobalScopes()
            ->where('instrument_id', $model->instrument_id)
            ->where('code', 'like', $prefix.'-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.'-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    public function instrument()
    {
        return $this->belongsTo(Instrument::class);
    }

    public function condition()
    {
        return $this->belongsTo(Condition::class);
    }

    public function logs()
    {
        return $this->hasMany(InstrumentStockLog::class);
    }
}
