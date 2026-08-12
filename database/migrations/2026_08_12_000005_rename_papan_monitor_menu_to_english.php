<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Menu grup Monitoring diselaraskan ke bahasa Inggris:
    // "Papan Monitor (TV)" → "Monitor Board (TV)".
    public function up(): void
    {
        DB::table('menus')->where('url', '/monitor')->update([
            'name' => 'Monitor Board (TV)',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', '/monitor')->update([
            'name' => 'Papan Monitor (TV)',
            'updated_at' => now(),
        ]);
    }
};
