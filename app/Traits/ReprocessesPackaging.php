<?php

namespace App\Traits;

use App\Models\Packaging;
use App\Models\PipelineEvent;
use App\Models\Sterilization;
use Illuminate\Support\Collection;

/**
 * Membuka RONDE PENGEMASAN ULANG untuk unit yang harus kembali ke tahap Packaging.
 *
 * SATU sumber kebenaran untuk dua pemicu yang berbeda:
 *  - unit GAGAL steril (SterilizationPipelineController::validateUnits);
 *  - unit KEDALUWARSA yang ditarik dari rak (SterileExpiryController::repackage).
 *
 * Keduanya menghasilkan bentuk data yang sama persis, jadi logikanya tidak boleh
 * ditulis dua kali: begitu salah satu diubah tanpa yang lain, satu jalur akan
 * meninggalkan PKG lama yang tidak di-void atau RPK tanpa `reprocess_of`, dan
 * riwayat pengemasan unit itu putus di tengah.
 *
 * Yang dikerjakan untuk tiap PKG yang memuat unit sasaran:
 *  1. `packaging_item` unit sasaran di-void (`disabled`) — isi PKG lama menyusut
 *     tinggal unit yang tidak ditarik, dan tetap terlihat di History;
 *  2. PKG lama SENDIRI hanya di-void bila SELURUH isinya ikut ditarik. Pada
 *     penarikan sebagian, recordnya dibiarkan tampil berisi unit yang tersisa —
 *     kalau ikut di-void, jejak pengemasan unit yang tidak ditarik hilang dari
 *     tampilan petugas;
 *  3. dibuat record `RPK` baru (ronde berikutnya, `reprocess_of` menunjuk PKG asal)
 *     berisi unit sasaran saja, dengan NOMOR LABEL BARU — sehingga batch itu muncul
 *     lagi di tab Packaging dan label lamanya tidak dipakai ulang.
 */
trait ReprocessesPackaging
{
    /**
     * `stock_ids` pada hasil = unit yang BENAR-BENAR tertangani. Unit yang tidak
     * ketemu di PKG mana pun TIDAK masuk daftar itu, sehingga pemanggil bisa
     * menolaknya alih-alih diam-diam membiarkannya hilang dari pipeline.
     *
     * @param  array<int,int>  $stockIds  unit yang ditarik kembali ke Packaging
     * @param  string  $reason  potongan kalimat untuk jejak pipeline, mis. "unit gagal steril STR-001 dikembalikan"
     * @return array{packagings: array<int,Packaging>, stock_ids: array<int,int>}
     */
    protected function openReprocessRound(Sterilization $sterilization, array $stockIds, string $reason): array
    {
        $target = collect($stockIds)->map(fn ($v) => (int) $v)->filter()->unique();

        if ($target->isEmpty()) {
            return ['packagings' => [], 'stock_ids' => []];
        }

        $sterilization->loadMissing('packagings.washing.production.items');

        $actor = auth()->user()?->name;
        $created = [];
        $handled = collect();

        foreach ($sterilization->packagings as $pkg) {
            if ($pkg->disabled) {
                continue;
            }

            // Isi ronde ini dibaca dari packaging_item (bukan seluruh unit produksi),
            // supaya PKG yang isinya sudah menyusut karena re-proses sebelumnya tidak
            // salah dinilai.
            $pkgStockIds = $pkg->items()->where('disabled', false)
                ->pluck('instrument_stock_id')->filter()->map(fn ($v) => (int) $v);

            $hit = $pkgStockIds->intersect($target);

            // Lewati PKG yang tidak memuat satu pun unit sasaran.
            if ($hit->isEmpty()) {
                continue;
            }

            $pkg->items()
                ->where('disabled', false)
                ->whereIn('instrument_stock_id', $hit->all())
                ->update(['disabled' => true, 'disabled_at' => now(), 'updated_by' => $actor]);

            if ($pkgStockIds->diff($target)->isEmpty()) {
                $pkg->disabled = true;
                $pkg->disabled_at = now();
                $pkg->save();
            }

            $newPkg = Packaging::create([
                'prefix' => Packaging::PREFIX_REPROCESS,
                'washing_code' => $pkg->washing_code,
                'reprocess_of' => $pkg->id,
                'round' => Packaging::nextRound($pkg->washing_code),
                'status' => Packaging::STATUS_DIPROSES,
            ]);

            $this->copyItemsIntoRound($newPkg, $pkg, $hit);

            PipelineEvent::record(PipelineEvent::STAGE_PACKAGING, $newPkg->full_code, PipelineEvent::ACTION_DIBUAT, [
                'note' => 'Pengemasan ulang — '.$reason.' (PKG lama '.$pkg->full_code.' di-void)',
            ]);

            $created[] = $newPkg;
            $handled = $handled->concat($hit->all());
        }

        return [
            'packagings' => $created,
            'stock_ids' => $handled->unique()->values()->all(),
        ];
    }

    /**
     * Isi record RPK baru dengan unit sasaran.
     *
     * Sumber utamanya `production_item`: di sanalah `package_no` berada, dan nomor
     * itu yang menyusun label barunya (`barcodeNoFor`). Bila baris produksinya tidak
     * terlacak — data lama, atau rantai washing→production putus — isinya disalin
     * dari `packaging_item` PKG asal supaya ronde barunya tetap punya daftar unit.
     * Tanpa cadangan itu RPK lahir kosong, dan `PackagingController::batchPayload`
     * menafsirkan record tanpa item sebagai "pakai SELURUH unit produksi" — satu
     * unit kedaluwarsa akan menyeret seluruh batch ikut dikemas ulang.
     *
     * @param  Collection<int,int>  $hit
     */
    private function copyItemsIntoRound(Packaging $newPkg, Packaging $sourcePkg, Collection $hit): void
    {
        $prodItems = ($sourcePkg->washing?->production?->items ?? collect())
            ->whereIn('instrument_stock_id', $hit->all());

        if ($prodItems->isNotEmpty()) {
            foreach ($prodItems as $pi) {
                $newPkg->items()->create([
                    'instrument_stock_id' => $pi->instrument_stock_id,
                    'source' => $pi->source,
                    'package_name' => $pi->package_name,
                    // Label baru: nomornya ikut kode RPK, bukan warisan PKG lama.
                    'barcode_no' => $newPkg->barcodeNoFor($pi->package_no),
                ]);
            }

            return;
        }

        $fallback = $sourcePkg->items()
            ->whereIn('instrument_stock_id', $hit->all())
            ->get();

        foreach ($fallback as $item) {
            $newPkg->items()->create([
                'instrument_stock_id' => $item->instrument_stock_id,
                'source' => $item->source,
                'package_name' => $item->package_name,
                // Tanpa production_item nomor setnya tidak diketahui; labelnya tetap
                // baru karena kode RPK-nya berbeda.
                'barcode_no' => $newPkg->barcodeNoFor(null),
            ]);
        }
    }
}
