<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag per-menu: saat halaman menu (yang punya url) dibuka, apakah sidebar
     * utama tetap terbuka (true) atau otomatis ditutup/collapse (false).
     */
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('open_sidebar')->default(true)->after('is_open');
        });

        // "Pengaturan" menutup sidebar utama saat dibuka (mempertahankan perilaku
        // sebelumnya yang di-hardcode di frontend, kini digerakkan oleh data).
        DB::table('menus')->where('url', '/pengaturan')->update(['open_sidebar' => false]);
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('open_sidebar');
        });
    }
};
