<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Master mesin pencuci (washer disinfector) — tahap Cleaning & Disinfection.
 * Kode auto WSH-NNN dipakai sebagai barcode yang dipindai petugas. Ambang
 * suhu & durasi dipakai untuk mendeteksi kegagalan parameter pencucian.
 */
class WasherMachine extends Model
{
    use HasAuditColumns, HasAutoCode;

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    protected $fillable = [
        'code',
        'name',
        'location',
        'min_temperature',
        'max_temperature',
        'min_duration_minutes',
        'max_duration_minutes',
        'sterile_shelf_life_days',
        'status',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'min_temperature' => 'decimal:2',
        'max_temperature' => 'decimal:2',
        'min_duration_minutes' => 'integer',
        'max_duration_minutes' => 'integer',
        'sterile_shelf_life_days' => 'integer',
    ];

    protected static function generateUniqueCode($model): string
    {
        $maxCode = static::withoutGlobalScopes()
            ->where('code', 'like', 'WSH-%')
            ->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'WSH-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Evaluasi parameter pencucian terhadap ambang mesin. Mengembalikan daftar
     * pesan kegagalan (kosong = lolos).
     *
     * @return list<string>
     */
    public function evaluate(?float $temperature, ?int $durationMinutes): array
    {
        $alerts = [];

        if ($temperature !== null) {
            if ($this->min_temperature !== null && $temperature < (float) $this->min_temperature) {
                $alerts[] = "Suhu {$temperature}°C di bawah minimum mesin ({$this->min_temperature}°C).";
            }
            if ($this->max_temperature !== null && $temperature > (float) $this->max_temperature) {
                $alerts[] = "Suhu {$temperature}°C di atas maksimum mesin ({$this->max_temperature}°C).";
            }
        }

        if ($durationMinutes !== null) {
            if ($this->min_duration_minutes !== null && $durationMinutes < $this->min_duration_minutes) {
                $alerts[] = "Durasi {$durationMinutes} menit di bawah minimum mesin ({$this->min_duration_minutes} menit).";
            }
            if ($this->max_duration_minutes !== null && $durationMinutes > $this->max_duration_minutes) {
                $alerts[] = "Durasi {$durationMinutes} menit di atas maksimum mesin ({$this->max_duration_minutes} menit).";
            }
        }

        return $alerts;
    }
}
