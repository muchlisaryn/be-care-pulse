<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasAutoCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Master mesin pencuci (washer disinfector) — tahap Cleaning & Disinfection.
 * Kode auto WSH-NNN dipakai sebagai barcode yang dipindai petugas. Suhu & durasi
 * standar dipakai sebagai batas minimum untuk mendeteksi kegagalan pencucian.
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
        'temperature',
        'duration_minutes',
        'status',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'duration_minutes' => 'integer',
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
     * Evaluasi parameter pencucian terhadap standar mesin (batas minimum).
     * Hasil di bawah suhu/durasi standar ditandai gagal. Mengembalikan daftar
     * pesan kegagalan (kosong = lolos).
     *
     * @return list<string>
     */
    public function evaluate(?float $temperature, ?int $durationMinutes): array
    {
        $alerts = [];

        if ($temperature !== null && $this->temperature !== null && $temperature < (float) $this->temperature) {
            $alerts[] = "Suhu {$temperature}°C di bawah standar mesin ({$this->temperature}°C).";
        }

        if ($durationMinutes !== null && $this->duration_minutes !== null && $durationMinutes < $this->duration_minutes) {
            $alerts[] = "Durasi {$durationMinutes} menit di bawah standar mesin ({$this->duration_minutes} menit).";
        }

        return $alerts;
    }
}
