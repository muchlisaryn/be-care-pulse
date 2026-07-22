<?php

namespace Database\Seeders;

use App\Models\Instrument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Memulihkan kolom `image` instrumen dari berkas fisik yang tersisa di
 * public/uploads/instruments/.
 *
 * Latar: gambar instrumen disimpan sebagai file di public/ sementara referensinya
 * ada di kolom DB `instruments.image`. Setiap `migrate:fresh` mengosongkan tabel
 * sehingga referensi hilang, padahal file uploadnya tetap ada (jadi yatim).
 * Seeder ini menautkan ulang tiap instrumen ke berkas upload TERBARUNYA.
 *
 * Idempotent & aman: hanya mengisi instrumen yang `image`-nya masih kosong —
 * tidak pernah menimpa gambar yang sudah di-set. Berkas dinamai
 * `ins-{instrument_id}-{timestamp}.{ext}` oleh InstrumentController::uploadImage.
 */
class InstrumentImageSeeder extends Seeder
{
    public function run(): void
    {
        $dir = public_path('uploads/instruments');
        if (! is_dir($dir)) {
            return;
        }

        // Berkas upload terbaru per instrument id (timestamp terbesar menang).
        $latest = [];
        foreach (glob($dir.'/ins-*') as $path) {
            $file = basename($path);
            if (preg_match('/^ins-(\d+)-(\d+)\./', $file, $m)) {
                $id = (int) $m[1];
                $ts = (int) $m[2];
                if (! isset($latest[$id]) || $ts > $latest[$id]['ts']) {
                    $latest[$id] = ['ts' => $ts, 'file' => $file];
                }
            }
        }

        if (empty($latest)) {
            return;
        }

        // Hanya isi instrumen yang belum punya gambar & punya berkas cocok.
        $instruments = Instrument::whereNull('image')
            ->whereIn('id', array_keys($latest))
            ->get(['id']);

        foreach ($instruments as $instrument) {
            DB::table('instruments')
                ->where('id', $instrument->id)
                ->update(['image' => 'uploads/instruments/'.$latest[$instrument->id]['file']]);
        }
    }
}
