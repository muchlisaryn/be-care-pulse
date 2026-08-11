<?php

namespace App\Traits;

use App\Models\InstrumentStorage;
use App\Models\SterilizationItem;
use Illuminate\Support\Collection;

/**
 * Aturan hitung "jumlah instrumen" gudang steril — SATU sumber kebenaran untuk
 * semua halaman yang menampilkan angka gudang (Storage Steril & Alat Kedaluwarsa
 * Steril) agar angkanya tidak pernah berbeda antar halaman.
 *
 * ATURAN: baris `paket` dihitung per SET (satu bungkus/label kemasan = 1, berapa
 * pun instrumen di dalamnya), baris `satuan` dihitung per unit (1). Jadi satu set
 * berisi 5 instrumen tetap bernilai 1 — JANGAN dihitung per instrumen.
 */
trait CountsSterileItems
{
    /**
     * Jumlah "instrumen" menurut aturan tampilan: baris `paket` dihitung per SET
     * (dikelompokkan per nomor label kemasan pada batch steril yang sama — satu label
     * = satu bungkus = satu set), baris `satuan` dihitung per unit. Bungkus tanpa
     * nomor label dihitung sebagai set tersendiri agar jumlahnya tidak mengecil palsu.
     *
     * Pengelompokan set dibatasi PER RAK, meniru halaman Inventaris Gudang yang
     * memecah isi per rak lebih dulu: satu paket yang unitnya tersebar di dua rak
     * tampil sebagai satu set di masing-masing rak, jadi totalnya 2 — bukan 1.
     *
     * @param  Collection<int,InstrumentStorage>  $rows
     * @param  array{pairs: array<string,string>, stocks: array<int,string>}  $barcodes
     */
    protected function countAsItems(Collection $rows, array $barcodes): int
    {
        $count = 0;
        $seenSets = [];

        foreach ($rows as $s) {
            if (($s->productionItem?->source ?? 'satuan') !== 'paket') {
                $count++;

                continue;
            }

            if (isset($seenSets[$this->setKey($s, $barcodes)])) {
                continue;
            }
            $seenSets[$this->setKey($s, $barcodes)] = true;
            $count++;
        }

        return $count;
    }

    /**
     * Kunci identitas satu SET (satu bungkus steril) pada satu rak: rak + batch
     * steril + nomor label kemasan. Baris tanpa nomor label tidak boleh digabung
     * dengan baris tak berlabel lain, jadi kuncinya memakai id barisnya sendiri.
     *
     * @param  array{pairs: array<string,string>, stocks: array<int,string>}  $barcodes
     */
    protected function setKey(InstrumentStorage $s, array $barcodes): string
    {
        $barcode = $this->barcodeOf($s, $barcodes);

        return $barcode !== null
            ? $s->rack_code.'|'.$s->sterilization_id.'|'.$barcode
            : 'tanpa-label#'.$s->id;
    }

    /**
     * Nomor label kemasan satu baris gudang: utamakan pasangan batch steril + unit,
     * cadangan pakai label batch steril TERAKHIR unit tersebut.
     *
     * @param  array{pairs: array<string,string>, stocks: array<int,string>}  $barcodes
     */
    protected function barcodeOf(InstrumentStorage $s, array $barcodes): ?string
    {
        return $barcodes['pairs'][$s->sterilization_id.'|'.$s->instrument_stock_id]
            ?? $barcodes['stocks'][(int) $s->instrument_stock_id]
            ?? null;
    }

    /**
     * Nomor label kemasan (barcode bungkus steril) tiap baris gudang. Labelnya
     * dibawa `sterilization_items.packaging_barcode` — dipetakan sekali untuk
     * seluruh halaman agar tidak query per baris.
     *
     * Kunci utama pasangan `sterilization_id|instrument_stock_id`; baris gudang
     * lama yang tak punya sterilization_id memakai cadangan per instrument_stock_id
     * (label batch steril TERAKHIR unit itu).
     *
     * @param  Collection<int,InstrumentStorage>  $rows
     * @return array{pairs: array<string,string>, stocks: array<int,string>}
     */
    protected function packagingBarcodeMap(Collection $rows): array
    {
        $stockIds = $rows->pluck('instrument_stock_id')->filter()->unique()->values()->all();
        if (empty($stockIds)) {
            return ['pairs' => [], 'stocks' => []];
        }

        $pairs = [];
        $stocks = [];

        SterilizationItem::whereIn('instrument_stock_id', $stockIds)
            ->whereNotNull('packaging_barcode')
            ->orderBy('id')
            ->get(['sterilization_id', 'instrument_stock_id', 'packaging_barcode'])
            // Urut id ASC → batch terbaru menimpa yang lama pada peta cadangan.
            ->each(function ($it) use (&$pairs, &$stocks) {
                $pairs[$it->sterilization_id.'|'.$it->instrument_stock_id] = $it->packaging_barcode;
                $stocks[(int) $it->instrument_stock_id] = $it->packaging_barcode;
            });

        return ['pairs' => $pairs, 'stocks' => $stocks];
    }
}
