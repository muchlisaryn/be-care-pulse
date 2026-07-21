<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dua penyederhanaan pada production_item:
     *
     * 1. `package_image` dilebur ke `image`. Satu baris hanya pernah punya satu foto
     *    yang relevan — baris `paket` memakai foto katalog, baris `satuan` memakai
     *    foto instrumen — jadi dua kolom hanya bikin pemanggil harus memilih.
     *
     * 2. `package_no` kini WAJIB terisi di semua baris, termasuk `satuan`. Nomor ini
     *    jadi identitas satu satuan pesanan: tiap qty dapat satu nomor. Pesan
     *    "gunting 3 + set partus 3" menghasilkan nomor 1..6 — 3 nomor untuk masing
     *    -masing gunting, 3 nomor untuk masing-masing set. Semua unit dalam satu set
     *    berbagi nomor yang sama, sehingga data bisa dipetakan per package_no.
     */
    public function up(): void
    {
        // 1. Baris paket: pindahkan foto katalog ke `image`. COALESCE menjaga baris
        //    paket lama yang belum punya foto katalog tetap memakai foto instrumen.
        DB::statement("
            UPDATE production_item
            SET image = COALESCE(package_image, image)
            WHERE source = 'paket'
        ");

        Schema::table('production_item', function (Blueprint $table) {
            $table->dropColumn('package_image');
        });

        // 2. Isi package_no baris lama yang masih null, per batch produksi.
        //    Unit paket yang sudah bernomor dipertahankan; unit satuan diberi nomor
        //    lanjutan satu per unit, urut id agar hasilnya stabil bila diulang.
        foreach (DB::table('production_item')->distinct()->pluck('production_id') as $productionId) {
            $next = (int) DB::table('production_item')
                ->where('production_id', $productionId)
                ->max('package_no');

            $rows = DB::table('production_item')
                ->where('production_id', $productionId)
                ->whereNull('package_no')
                ->orderBy('id')
                ->get(['id', 'source', 'package_name']);

            // Unit paket lama tanpa nomor: satu nomor per nama paket (komposisi set
            // aslinya tidak terekam, jadi seluruh unit senama dianggap satu set).
            $noByPackage = [];

            foreach ($rows as $row) {
                if ($row->source === 'paket') {
                    $key = $row->package_name ?? 'Paket';
                    $noByPackage[$key] ??= ++$next;
                    $no = $noByPackage[$key];
                } else {
                    $no = ++$next;
                }

                DB::table('production_item')->where('id', $row->id)->update(['package_no' => $no]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('production_item', function (Blueprint $table) {
            $table->string('package_image')->nullable()->after('image');
        });
    }
};
