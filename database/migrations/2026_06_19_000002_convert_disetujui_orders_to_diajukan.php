<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Status "disetujui" dihapus dari alur. Order lama yang masih "disetujui"
    // dikembalikan ke "diajukan" agar tetap bisa diproses (diterima/dipinjamkan).
    public function up(): void
    {
        DB::table('order')->where('status', 'disetujui')->update(['status' => 'diajukan']);
    }

    public function down(): void
    {
        // Tidak dapat dipulihkan secara akurat — biarkan sebagai "diajukan".
    }
};
