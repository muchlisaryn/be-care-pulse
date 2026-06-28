<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tata ulang urutan menu grup "Transaksi" pada title Cssd menjadi:
     * 1. Order Instrumen  (/cssd/order/instrumen)
     * 2. Tracking Order   (/cssd/monitoring)
     * 3. Distribusi BMHP  (/cssd/distribusi)
     */
    private array $order = [
        '/cssd/order/instrumen' => 1,
        '/cssd/monitoring' => 2,
        '/cssd/distribusi' => 3,
    ];

    public function up(): void
    {
        foreach ($this->order as $url => $sort) {
            DB::table('menus')->where('url', $url)->update([
                'sort_order' => $sort,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Urutan sebelumnya: Order Instrumen=2, Distribusi BMHP=3, Tracking Order=4.
        $previous = [
            '/cssd/order/instrumen' => 2,
            '/cssd/distribusi' => 3,
            '/cssd/monitoring' => 4,
        ];

        foreach ($previous as $url => $sort) {
            DB::table('menus')->where('url', $url)->update([
                'sort_order' => $sort,
                'updated_at' => now(),
            ]);
        }
    }
};
