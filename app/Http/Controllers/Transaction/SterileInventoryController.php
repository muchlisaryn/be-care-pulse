<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStorage;
use App\Traits\CountsSterileItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaman Gudang Steril → tab **Inventaris** (`/cssd/storage-steril?tab=inventaris`).
 *
 * SENGAJA berdiri sendiri, tidak menumpang endpoint lain: aturan tampilan tab ini
 * berbeda dari mana pun. Di sini baris yang sudah kedaluwarsa (atau tersimpan tanpa
 * tanggal kedaluwarsa) TETAP DITAMPILKAN — petugas justru perlu tahu barangnya ada di
 * rak tapi harus diproses ulang — hanya ditandai tidak bisa dipesan lewat
 * `can_distribute` / `blocked_reason`. Angka "siap-order" di halaman Order Instrumen
 * kebalikannya: sama sekali tidak menghitung baris itu.
 *
 * Yang tetap dibagi dengan tempat lain hanyalah DEFINISI barisnya
 * (`InstrumentStorage::sterilePool()` + `blockedPackagingBarcodes()`), karena justru itu
 * yang tidak boleh berbeda: kalau daftar ini berangkat dari baris yang lain, penandanya
 * langsung berbohong.
 */
class SterileInventoryController extends Controller
{
    use CountsSterileItems;

    /** Ambang early-warning (hari) sebelum masa steril habis. */
    private const EXPIRY_ALERT_DAYS = 7;

    /**
     * GET /api/master/sterile-inventory
     *
     * Daftar isi gudang steril + lokasi rak + status kedaluwarsa, diurutkan dari yang
     * paling cepat kedaluwarsa (baris tanpa tanggal ditaruh paling akhir).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'days' => 'nullable|integer|min:0',
            'search' => 'nullable|string|max:255',
        ]);

        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));

        $rows = InstrumentStorage::with([
            'instrumentStock.instrument',
            'productionItem.production',
            'sterilization',
        ])
            ->sterilePool()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('rack_code', 'like', "%{$s}%")
                    ->orWhereHas('instrumentStock', fn ($u) => $u->where('code', 'like', "%{$s}%"))
                    ->orWhereHas('instrumentStock.instrument', fn ($i) => $i->where('name', 'like', "%{$s}%"))
                    // Nama & kode yang TAMPIL berasal dari production_item, ikut dicari.
                    ->orWhereHas('productionItem', fn ($p) => $p->where('name', 'like', "%{$s}%")
                        ->orWhere('kode_instrumen', 'like', "%{$s}%"))
                    // Nomor label kemasan yang tercetak di bungkus sterilnya.
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('sterilization_items')
                        ->whereColumn('sterilization_items.instrument_stock_id', 'instrument_storages.instrument_stock_id')
                        ->whereColumn('sterilization_items.sterilization_id', 'instrument_storages.sterilization_id')
                        ->whereNull('sterilization_items.deleted_by')
                        ->where('sterilization_items.packaging_barcode', 'like', "%{$s}%")))
            )
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(20);

        $barcodes = $this->packagingBarcodeMap($rows->getCollection());
        $blocked = InstrumentStorage::blockedPackagingBarcodes();

        $rows->getCollection()->transform(
            fn (InstrumentStorage $s) => $this->row($s, $days, $barcodes, $blocked)
        );

        return $this->success('Inventaris gudang steril berhasil diambil.', $rows);
    }

    /**
     * GET /api/master/sterile-inventory/summary
     *
     * Kartu statistik tab ini. Dihitung dari SELURUH pool (bukan cuma halaman yang
     * sudah dimuat), memakai baris yang sama persis dengan index() — kalau tidak,
     * angkanya tidak akan cocok dengan daftarnya.
     *
     * ATURAN HITUNG: paket per SET (satu bungkus/label = 1), satuan per unit — paket
     * berisi 5 instrumen tetap bernilai 1.
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['days' => 'nullable|integer|min:0']);

        $days = max(0, (int) $request->input('days', self::EXPIRY_ALERT_DAYS));
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($days);

        $rows = InstrumentStorage::sterilePool()
            ->with('productionItem:id,source,package_name')
            ->get([
                'id', 'instrument_stock_id', 'sterilization_id', 'production_item_id',
                'expiry_date', 'rack_code',
            ]);

        $barcodes = $this->packagingBarcodeMap($rows);

        return $this->success('Ringkasan gudang steril berhasil diambil.', [
            'total' => $this->countAsItems($rows, $barcodes),
            'alert' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date
                    && $s->expiry_date->startOfDay()->gte($today)
                    && $s->expiry_date->startOfDay()->lte($limit)),
                $barcodes
            ),
            'expired' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date && $s->expiry_date->startOfDay()->lt($today)),
                $barcodes
            ),
            // Tersimpan tanpa tanggal kedaluwarsa: tetap dipajang di daftar, tapi tidak
            // bisa dipesan — sterilitasnya tidak bisa dijamin.
            'no_expiry' => $this->countAsItems(
                $rows->filter(fn ($s) => $s->expiry_date === null),
                $barcodes
            ),
        ]);
    }

    /**
     * Satu baris daftar. `can_distribute` / `blocked_reason` memakai urutan pemeriksaan
     * yang sama dengan OrderController::distributionCandidates(), jadi penanda di layar
     * tidak bisa berbeda dari kenyataan saat tombol Distribusikan ditekan.
     *
     * @param  array<string,array<string,string>>  $barcodes
     * @param  array<int,string>  $blocked
     */
    private function row(InstrumentStorage $s, int $days, array $barcodes, array $blocked): array
    {
        $prod = $s->productionItem;
        $barcode = $barcodes['pairs'][$s->sterilization_id.'|'.$s->instrument_stock_id]
            ?? $barcodes['stocks'][(int) $s->instrument_stock_id]
            ?? null;

        $daysToExpiry = null;
        $alert = false;
        $expired = false;

        if ($s->expiry_date) {
            $daysToExpiry = (int) now()->startOfDay()->diffInDays($s->expiry_date->copy()->startOfDay(), false);
            $expired = $daysToExpiry < 0;
            $alert = $daysToExpiry <= $days; // termasuk yang sudah lewat
        }

        $blockedReason = match (true) {
            $s->expiry_date === null => 'Tanpa tanggal kedaluwarsa',
            $expired => 'Kedaluwarsa',
            $barcode !== null && in_array($barcode, $blocked, true) => 'Sebungkus dengan unit kedaluwarsa',
            default => null,
        };

        return [
            'id' => $s->id,
            'rack_code' => $s->rack_code,
            'stored_at' => $s->stored_at,
            'expiry_date' => $s->expiry_date,
            'days_to_expiry' => $daysToExpiry,
            'alert' => $alert,
            'expired' => $expired,
            // Bisa dipesan/didistribusikan atau tidak, beserta alasannya bila tidak.
            'can_distribute' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
            // Asal & nama paket bersumber dari production_item (snapshot batch produksi).
            'source' => $prod?->source ?? 'satuan',
            'package_name' => $prod?->package_name,
            // Nomor label kemasan yang tercetak di bungkus sterilnya — satu label =
            // satu bungkus, jadi seluruh unit satu set berbagi nomor yang sama.
            'barcode_no' => $barcode,
            // Kode batch produksi asal unit (PRD-...).
            'production_code' => $prod?->production?->code,
            // Nama & kode unit bersumber dari production_item (snapshot batch produksi);
            // relasi master instrumen hanya dipakai untuk foto & sebagai cadangan.
            'unit' => [
                'id' => $s->instrument_stock_id,
                'code' => $prod?->kode_instrumen ?? $s->instrumentStock?->code,
                'instrument' => $prod?->name ?? $s->instrumentStock?->instrument?->name,
                'image_url' => $s->instrumentStock?->instrument?->image_url,
            ],
            // Baris di tab ini selalu pool bebas (scope sterilePool), jadi tidak pernah
            // terikat order — field `order` sengaja tidak dikirim.
            'batch' => $s->sterilization?->code,
        ];
    }
}
