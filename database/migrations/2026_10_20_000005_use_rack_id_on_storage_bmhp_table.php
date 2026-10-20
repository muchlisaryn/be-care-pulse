<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `storage_bmhp` untuk database yang dibuat sebelum migrasi pembuatnya dirapikan:
     *  - `rack_code` (teks nama rak) → `rack_id` (FK ke `racks`). Diisi dari nama
     *    rak yang sama; nama yang belum ada di master dibuatkan raknya.
     *  - `status` dibuang: hanya pernah `tersimpan` — keluar/void dibaca dari
     *    `removed_at` / `disabled_at`.
     *  - `expiry_date` dibuang: salinan `packaging_bmhp.expiry_date`, kini dibaca
     *    langsung dari kemasannya.
     */
    public function up(): void
    {
        if (Schema::hasColumn('storage_bmhp', 'rack_code')) {
            if (! Schema::hasColumn('storage_bmhp', 'rack_id')) {
                Schema::table('storage_bmhp', function (Blueprint $table) {
                    $table->foreignId('rack_id')->nullable()->after('barcode_no')->constrained('racks');
                });
            }

            $names = DB::table('storage_bmhp')->whereNull('rack_id')->distinct()->pluck('rack_code');
            foreach ($names as $name) {
                $rackId = DB::table('racks')->where('name', $name)->whereNull('deleted_by')->value('id')
                    ?? DB::table('racks')->insertGetId([
                        'name' => $name,
                        'created_by' => 'migrasi',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('storage_bmhp')->whereNull('rack_id')->where('rack_code', $name)->update(['rack_id' => $rackId]);
            }

            Schema::table('storage_bmhp', function (Blueprint $table) {
                $table->unsignedBigInteger('rack_id')->nullable(false)->change();
                $table->dropColumn('rack_code');
            });
        }

        $drop = array_values(array_filter(['status', 'expiry_date'], fn ($c) => Schema::hasColumn('storage_bmhp', $c)));
        if ($drop) {
            Schema::table('storage_bmhp', fn (Blueprint $table) => $table->dropColumn($drop));
        }
    }

    public function down(): void
    {
        // Sengaja kosong — kolom lama sudah tidak dipakai.
    }
};
