<?php

namespace App\Traits;

use App\Models\Sterilization;
use App\Models\SterilizationBmhp;

/**
 * Kode batch sterilisasi {kode mesin}-{ddmmyy}-{urut 2 digit}, mis. AUT01-191026-03.
 * Dipakai bersama sterilisasi instrumen (`sterilizations`) & BMHP
 * (`sterilization_bmhp`): nomor urutnya urutan sterilisasi hari itu LINTAS kedua
 * tabel & lintas mesin, jadi satu kode tidak pernah dipakai dua batch.
 */
trait GeneratesSterilizationCode
{
    public static function nextSterilizationCode(string $machineCode): string
    {
        $day = now()->format('dmy');
        $pattern = '/-'.$day.'-(\d+)$/';

        $last = collect([Sterilization::class, SterilizationBmhp::class])
            ->flatMap(fn ($model) => $model::withoutGlobalScopes()
                ->where('code', 'like', '%-'.$day.'-%')
                ->pluck('code'))
            ->map(fn ($code) => preg_match($pattern, $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $machineCode.'-'.$day.'-'.str_pad($last + 1, 2, '0', STR_PAD_LEFT);
    }
}
