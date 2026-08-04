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
            $existing = InstrumentStock::withoutGlobalScopes()
                ->where('instrument_id', $instrument->id)
                ->count();

            // Idempotent: sasarannya JUMLAH TOTAL unit per instrumen, bukan "tambah
            // PER_INSTRUMENT lagi". Versi lama melanjutkan nomor urut dari unit yang
            // sudah ada — memang tidak menabrak `code` unik, tapi setiap `db:seed`
            // ulang menambah 5 unit baru per instrumen sehingga master membengkak
            // diam-diam. Bila kuotanya sudah terpenuhi, loop ini tidak jalan.
            for ($seq = $existing + 1; $seq <= self::PER_INSTRUMENT; $seq++) {
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
