<?php
/**
 * Pra-periksa sebelum menjalankan migrasi di server.
 *
 * Migrasi `add_active_stock_unique_to_instrument_storages` SENGAJA gagal bila
 * ada unit yang punya lebih dari satu baris rak aktif. Skrip ini memeriksanya
 * lebih dulu supaya ketahuan sebelum deploy, bukan di tengah deploy.
 *
 * Aman dijalankan: hanya membaca, tidak mengubah apa pun.
 *
 *   php praperiksa-deploy.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$masalah = 0;

echo "=== 1. Unit dengan lebih dari satu baris rak AKTIF ===\n";
echo "    (penghalang migrasi 2026_08_22_000008)\n\n";

$duplikat = DB::table('instrument_storages as s')
    ->leftJoin('instrument_stocks as st', 'st.id', '=', 's.instrument_stock_id')
    ->leftJoin('instruments as i', 'i.id', '=', 'st.instrument_id')
    ->select('s.instrument_stock_id', 'st.code', 'i.name', DB::raw('COUNT(*) AS jumlah'))
    ->whereNull('s.deleted_by')
    ->whereNull('s.removed_at')
    ->whereNull('s.order_id')
    ->groupBy('s.instrument_stock_id', 'st.code', 'i.name')
    ->having('jumlah', '>', 1)
    ->get();

if ($duplikat->isEmpty()) {
    echo "    AMAN — tidak ada. Migrasi bisa jalan.\n";
} else {
    $masalah++;
    echo '    MASALAH — '.$duplikat->count()." unit bentrok. Migrasi AKAN GAGAL.\n\n";
    foreach ($duplikat as $d) {
        echo "      unit#{$d->instrument_stock_id} ".str_pad($d->code ?? '-', 14)
            .str_pad($d->name ?? '-', 24)." {$d->jumlah} baris aktif\n";

        $baris = DB::table('instrument_storages')
            ->where('instrument_stock_id', $d->instrument_stock_id)
            ->whereNull('deleted_by')->whereNull('removed_at')->whereNull('order_id')
            ->orderBy('id')
            ->get(['id', 'rack_code', 'status', 'stored_at', 'expiry_date']);

        foreach ($baris as $b) {
            echo "         storage#{$b->id}  rak=".str_pad($b->rack_code ?? '-', 10)
                .' simpan='.($b->stored_at ?? '-').'  kedaluwarsa='.($b->expiry_date ?? '-')."\n";
        }
        echo "         → sisakan SATU (biasanya yang paling baru), tutup sisanya:\n";
        echo "           UPDATE instrument_storages SET status='keluar', removed_at=NOW()\n";
        echo "           WHERE id IN (...id yang dibuang...);\n\n";
    }
}

echo "\n=== 2. Unit sedang DIPINJAM tapi masih punya baris rak aktif ===\n";
echo "    (gejala double-order yang jadi alasan index dipasang)\n\n";

$hantu = DB::table('instrument_storages as s')
    ->join('instrument_stocks as st', 'st.id', '=', 's.instrument_stock_id')
    ->whereNull('s.deleted_by')->whereNull('s.removed_at')->whereNull('s.order_id')
    ->where('st.status', 'dipinjam')
    ->get(['s.id as storage_id', 'st.code', 's.rack_code']);

if ($hantu->isEmpty()) {
    echo "    AMAN — tidak ada.\n";
} else {
    $masalah++;
    echo '    MASALAH — '.$hantu->count()." baris. Tidak menggagalkan migrasi,\n";
    echo "    tapi stok ini terhitung ganda dan bisa terpesan dua kali:\n";
    foreach ($hantu as $h) {
        echo "      storage#{$h->storage_id}  {$h->code}  rak={$h->rack_code}\n";
    }
}

echo "\n=== 3. Master Nafsul yang wajib ada ===\n\n";
foreach ([
    'member_statuses' => ['code', 'STS1', 'status bawaan form pendaftaran anggota'],
    'group_leaders' => ['name', 'Pribadi', 'penampung anggota perorangan'],
] as $tabel => [$kolom, $nilai, $guna]) {
    if (! Schema::hasTable($tabel)) {
        echo "    $tabel: TABEL TIDAK ADA\n";
        $masalah++;

        continue;
    }
    $ada = DB::table($tabel)->where($kolom, $nilai)->whereNull('deleted_by')->exists();
    printf("    %-18s %-10s %s   (%s)\n", $tabel, $nilai, $ada ? 'ADA' : 'TIDAK ADA <-- jalankan seeder', $guna);
    if (! $ada) {
        $masalah++;
    }
}

echo "\n=== 4. Kolom baru sudah ada? ===\n\n";
foreach ([
    'transaction_headers' => ['member_deduction_type', 'member_deduction_input'],
    'instrument_storages' => ['active_stock_id'],
] as $tabel => $kolomBaru) {
    foreach ($kolomBaru as $k) {
        $ada = Schema::hasTable($tabel) && Schema::hasColumn($tabel, $k);
        printf("    %-22s %-24s %s\n", $tabel, $k, $ada ? 'sudah' : 'belum (akan dibuat migrasi)');
    }
}

echo "\n".str_repeat('=', 60)."\n";
echo $masalah === 0
    ? "SIAP DEPLOY — tidak ada penghalang.\n"
    : "ADA $masalah HAL YANG PERLU DIBERESKAN dulu (lihat di atas).\n";
