<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sisa menu grup "Master CSSD" yang masih berbahasa Indonesia:
     *   Mesin Sterilisator → Sterilizer Machine
     *   Rak                → Rack
     *
     * "Packaging" (/master/jenis-kemasan) sudah berbahasa Inggris sejak awal,
     * jadi tidak ikut diubah di sini.
     */
    private const RENAMES = [
        '/master/mesin-sterilisator' => ['Sterilizer Machine', 'Mesin Sterilisator'],
        '/master/rak'                => ['Rack', 'Rak'],
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
