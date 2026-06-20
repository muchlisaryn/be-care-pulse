<?php

namespace Database\Seeders;

use App\Models\Condition;
use App\Models\Instrument;
use App\Models\InstrumentStock;
use Illuminate\Database\Seeder;

class InstrumentStockSeeder extends Seeder
{
    /** Jumlah unit fisik yang dibuat per instrumen. */
    private const PER_INSTRUMENT = 5;

    public function run(): void
    {
        $conditionId = Condition::where('name', 'Baik')->value('id');

        foreach (Instrument::all() as $instrument) {
            // Lanjutkan nomor urut dari unit yang sudah ada agar idempoten saat di-run ulang.
            $existing = InstrumentStock::withoutGlobalScopes()
                ->where('instrument_id', $instrument->id)
                ->count();

            for ($i = 1; $i <= self::PER_INSTRUMENT; $i++) {
                $seq = $existing + $i;

                // Set code eksplisit (bukan mass-assign): aman walau event model dimatikan
                // (DatabaseSeeder pakai WithoutModelEvents). Bila event aktif, HasAutoCode
                // membiarkan code yang sudah terisi.
                $stock = new InstrumentStock([
                    'instrument_id' => $instrument->id,
                    'condition_id' => $conditionId,
                    'status' => InstrumentStock::STATUS_TERSEDIA,
                ]);
                $stock->code = $instrument->code.'-'.str_pad($seq, 3, '0', STR_PAD_LEFT);
                $stock->save();
            }
        }
    }
}
