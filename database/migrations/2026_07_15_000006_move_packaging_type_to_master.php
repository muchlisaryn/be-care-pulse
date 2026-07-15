<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pindahkan jenis kemasan dari konstanta (Packaging::PACKAGING_TYPES) ke master
     * `packaging_types`: isi 3 jenis bawaan, ganti kolom string `packaging.packaging_type`
     * menjadi FK `packaging_type_id`, lalu pindahkan data batch yang sudah dikemas.
     *
     * Nilai bawaan & pemetaannya sengaja ditulis di sini (bukan mengacu konstanta
     * model) agar migrasi tetap jalan setelah konstantanya dihapus dari kode.
     */
    private const DEFAULTS = [
        'linen' => ['code' => 'PKS-001', 'name' => 'Linen / Kain', 'shelf_life_days' => 7],
        'pouch' => ['code' => 'PKS-002', 'name' => 'Pouch Plastik', 'shelf_life_days' => 30],
        'container' => ['code' => 'PKS-003', 'name' => 'Container', 'shelf_life_days' => 30],
    ];

    public function up(): void
    {
        // 1. Isi master dengan jenis bawaan (idempoten — lewati yang sudah ada).
        foreach (self::DEFAULTS as $row) {
            if (DB::table('packaging_types')->where('code', $row['code'])->exists()) {
                continue;
            }
            DB::table('packaging_types')->insert([
                ...$row,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Kolom FK ke master.
        Schema::table('packaging', function (Blueprint $table) {
            $table->unsignedBigInteger('packaging_type_id')->nullable()->after('chemical_indicator');
        });

        // 3. Pindahkan data batch lama: string 'pouch' → id master PKS-002, dst.
        //    Batch tanpa jenis kemasan (dikemas sebelum fitur ini) tetap null.
        foreach (self::DEFAULTS as $oldValue => $row) {
            $id = DB::table('packaging_types')->where('code', $row['code'])->value('id');
            DB::table('packaging')->where('packaging_type', $oldValue)->update(['packaging_type_id' => $id]);
        }

        // 4. Kolom string lama tak dipakai lagi.
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropColumn('packaging_type');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->string('packaging_type')->nullable()->after('chemical_indicator');
        });

        foreach (self::DEFAULTS as $oldValue => $row) {
            $id = DB::table('packaging_types')->where('code', $row['code'])->value('id');
            if ($id) {
                DB::table('packaging')->where('packaging_type_id', $id)->update(['packaging_type' => $oldValue]);
            }
        }

        Schema::table('packaging', function (Blueprint $table) {
            $table->dropColumn('packaging_type_id');
        });

        DB::table('packaging_types')->whereIn('code', array_column(self::DEFAULTS, 'code'))->delete();
    }
};
