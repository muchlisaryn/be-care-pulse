<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sebagian menu grup "Master CSSD" diselaraskan ke bahasa Inggris:
     *   Ruangan       → Room
     *   Set Instrumen → Instrument Set
     *   Kondisi       → Condition
     *   Mesin Washer  → Washer Machine
     *
     * Item lain (BMHP, Mesin Sterilisator, Rak, Packaging) sengaja dibiarkan.
     */
    private const RENAMES = [
        '/master/ruangan'           => ['Room', 'Ruangan'],
        '/master/katalog-instrumen' => ['Instrument Set', 'Set Instrumen'],
        '/master/kondisi'           => ['Condition', 'Kondisi'],
        '/master/mesin-washer'      => ['Washer Machine', 'Mesin Washer'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $url => [$english]) {
            DB::table('menus')->where('url', $url)->update([
                'name' => $english,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $url => [, $indonesian]) {
            DB::table('menus')->where('url', $url)->update([
                'name' => $indonesian,
                'updated_at' => now(),
            ]);
        }
    }
};
