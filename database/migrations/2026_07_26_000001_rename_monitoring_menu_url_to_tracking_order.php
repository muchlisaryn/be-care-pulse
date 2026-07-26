<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Menu "Tracking Order" masih memakai url lama /cssd/monitoring. Samakan url-nya
    // dengan nama menu → /cssd/tracking-order (route frontend sudah di-rename).
    public function up(): void
    {
        DB::table('menus')->where('url', '/cssd/monitoring')->update([
            'url' => '/cssd/tracking-order',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', '/cssd/tracking-order')->update([
            'url' => '/cssd/monitoring',
            'updated_at' => now(),
        ]);
    }
};
