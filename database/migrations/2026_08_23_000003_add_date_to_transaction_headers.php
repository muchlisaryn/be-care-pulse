<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal transaksi kuitansi iuran Nafsul.
 *
 * Sebelum ini tanggal kuitansi cuma terbaca dari `created_at`, yaitu kapan
 * BARISNYA DIBUAT di sistem — bukan kapan uangnya diterima. Keduanya sering
 * berbeda: setoran hari Sabtu baru diinput Senin, dan kuitansi lama dicatat
 * ulang berbulan-bulan kemudian. Selama tidak ada kolomnya, laporan harian
 * memakai tanggal input dan tidak pernah cocok dengan buku kas.
 *
 * Nullable di database, tapi WAJIB di API (`validateData`). Nullable dipilih
 * supaya migrasi ini tidak perlu memaksakan nilai palsu pada baris lama lewat
 * DEFAULT; baris lama justru diisi dari `created_at`-nya sendiri di bawah, yang
 * merupakan perkiraan terbaik yang tersedia.
 *
 * Tipe `date`, bukan `timestamp`: yang dicatat kuitansi adalah HARI penerimaan.
 * Jam-menitnya tidak pernah dipakai, dan menyimpannya hanya membuat penyaringan
 * per tanggal harus selalu membungkus kolomnya dengan DATE().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transaction_headers', 'date')) {
            return;
        }

        Schema::table('transaction_headers', function (Blueprint $table) {
            $table->date('date')->nullable()->after('transaction_number');
        });

        // Baris lama: tanggal terbaik yang kita punya adalah hari barisnya dibuat.
        // Tanpa ini kuitansi lama tidak punya tanggal sama sekali dan hilang dari
        // setiap laporan yang menyaring per tanggal.
        DB::table('transaction_headers')
            ->whereNull('date')
            ->update(['date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transaction_headers', 'date')) {
            return;
        }

        Schema::table('transaction_headers', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};
