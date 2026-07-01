<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tata ulang urutan menu grup "Transaksi" pada title Cssd menjadi:
     * 1. Produksi CSSD    (/cssd/produksi)
     * 2. Storage Steril   (/cssd/storage-steril)
     * 3. Order Instrumen  (/cssd/order/instrumen)
     * 4. Tracking Order   (/cssd/monitoring)
     * 5. Distribusi BMHP  (/cssd/distribusi)
     */
    private array $order = [
        '/cssd/produksi' => 1,
        '/cssd/storage-steril' => 2,
        '/cssd/order/instrumen' => 3,
        '/cssd/monitoring' => 4,
        '/cssd/distribusi' => 5,
    ];

    public function up(): void
    {
        foreach ($this->order as $url => $sort) {
            DB::table('menus')->where('url', $url)->update(['sort_order' => $sort, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Urutan sebelumnya (v2): Produksi=1, Tracking=2, Storage=3, Order Instrumen=4, Distribusi=5.
        $previous = [
            '/cssd/produksi' => 1,
            '/cssd/monitoring' => 2,
            '/cssd/storage-steril' => 3,
            '/cssd/order/instrumen' => 4,
            '/cssd/distribusi' => 5,
        ];
        foreach ($previous as $url => $sort) {
            DB::table('menus')->where('url', $url)->update(['sort_order' => $sort, 'updated_at' => now()]);
        }
    }
};
