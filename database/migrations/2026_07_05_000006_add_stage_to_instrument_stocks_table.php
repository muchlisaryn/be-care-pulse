<?php

use App\Models\InstrumentStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tahap pipeline aktual unit (pencucian/pengemasan/sterilisasi/disimpan/dipinjam)
     * dipersist agar tracking mudah — kolom `status` sendiri hanya enum kasar.
     */
    public function up(): void
    {
        Schema::table('instrument_stocks', function (Blueprint $table) {
            $table->string('stage')->nullable()->after('status')->index();
        });

        // Backfill: hitung tahap untuk seluruh unit yang tidak `tersedia`.
        $ids = InstrumentStock::withoutGlobalScopes()
            ->where('status', '!=', InstrumentStock::STATUS_TERSEDIA)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            InstrumentStock::syncStages($ids);
        }
    }

    public function down(): void
    {
        Schema::table('instrument_stocks', function (Blueprint $table) {
            $table->dropIndex(['stage']);
            $table->dropColumn('stage');
        });
    }
};
