<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\PackagingItem;
use App\Models\ProductionItem;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Laporan CSSD Per Alat: satu baris per LABEL KEMASAN (`packaging_item.barcode_no`)
     * di setiap batch sterilisasi. Satu label = satu bungkus fisik, jadi seluruh unit
     * yang dikemas bersama lebur menjadi satu baris yang bisa di-expand.
     *
     * Sumber data = SterilizationItem → Sterilization + InstrumentStock, dengan nama
     * alat/paket dari SNAPSHOT `production_item` supaya laporan lama tidak berubah
     * saat master instrumen di-rename.
     *
     * Pengelompokannya sengaja TIDAK lagi memakai asal "paket"/"satuan" dari
     * order_item: label kemasan adalah yang benar-benar dipegang petugas, dan satu
     * order paket bisa terbagi ke beberapa bungkus (atau sebaliknya). Unit yang belum
     * punya label tidak pernah digabung — kalau digabung, dua bungkus berbeda akan
     * tampak sebagai satu baris.
     *
     * Filter: ?search (nama/kode alat), ?status, ?method, ?machine, ?result
     * (berhasil/gagal), ?date_from, ?date_to (tanggal sterilisasi). ?per_page boleh
     * dipakai untuk export (default 20, maks 2000).
     *
     * Unit yang GAGAL steril tetap dilaporkan (barisnya ditandai `failed`), bukan
     * disembunyikan — laporan ini juga dipakai menelusuri kegagalan.
     */
    public function cssdPerItem(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(Sterilization::STATUSES)],
            'method' => ['nullable', Rule::in(Sterilization::METHODS)],
            'machine' => 'nullable|string|max:255',
            'result' => ['nullable', Rule::in([Sterilization::RESULT_BERHASIL, Sterilization::RESULT_GAGAL])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1',
        ]);

        $perPage = min((int) ($request->per_page ?: 20), 2000);
        $page = max((int) ($request->page ?: 1), 1);

        // Ambil seluruh unit yang cocok (laporan dibatasi filter), lalu kelompokkan
        // per PAKET (satu baris per paket per batch) di sisi server — agar grup tidak
        // terpotong antar halaman. Instrumen satuan tetap satu baris per unit.
        $items = SterilizationItem::query()
            ->whereHas('sterilization', function ($q) use ($request) {
                $q->when($request->status, fn ($q, $s) => $q->where('status', $s))
                    ->when($request->method, fn ($q, $m) => $q->where('method', $m))
                    ->when($request->machine, fn ($q, $m) => $q->where('machine', $m))
                    ->when($request->date_from, fn ($q, $d) => $q->whereDate('sterilized_at', '>=', $d))
                    ->when($request->date_to, fn ($q, $d) => $q->whereDate('sterilized_at', '<=', $d));
            })
            // Berhasil / gagal dibaca dari `disabled` pada barisnya sendiri: unit yang
            // gagal steril di-void saat validasi batch (lihat
            // SterilizationPipelineController::validate) — recordnya tetap tersimpan.
            ->when(
                $request->result,
                fn ($q, $r) => $q->where('disabled', $r === Sterilization::RESULT_GAGAL)
            )
            ->when($request->search, fn ($q, $s) => $q->whereHas('instrumentStock', function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('instrument', fn ($q) => $q->where('name', 'like', "%{$s}%"));
            }))
            ->with(['sterilization', 'instrumentStock.instrument', 'instrumentStock.condition'])
            ->latest()
            ->get();

        // Nama paket per unit — cadangan bila snapshot production_item tidak punya
        // `package_name`: kunci (order_id, instrument_stock_id).
        $orderIds = $items->pluck('sterilization.order_id')->filter()->unique()->all();
        $orderItems = OrderItem::whereIn('order_id', $orderIds)
            ->get(['order_id', 'instrument_stock_id', 'source', 'package_name'])
            ->keyBy(fn ($oi) => $oi->order_id.'-'.$oi->instrument_stock_id);

        // Nama instrumen/paket dari SNAPSHOT production_item (bukan master). keyBy pada
        // urutan id ASC → entri TERAKHIR (batch terbaru) menang per unit.
        $prodByStock = ProductionItem::whereIn(
            'instrument_stock_id',
            $items->pluck('instrument_stock_id')->filter()->unique()
        )->orderBy('id')->get()->keyBy('instrument_stock_id');

        $barcodeByStock = $this->barcodeNoByStock(
            $items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->unique()->all()
        );

        $groups = [];
        foreach ($items as $item) {
            $batch = $item->sterilization;
            $stock = $item->instrumentStock;
            $oi = $orderItems->get($batch?->order_id.'-'.$item->instrument_stock_id);
            $prod = $prodByStock->get($item->instrument_stock_id);
            // Label yang unit ini BENAR-BENAR bawa saat batch itu disterilkan —
            // tersimpan di barisnya sendiri, jadi laporan lama tidak ikut bergeser
            // saat unitnya dikemas ulang di siklus berikutnya. Baris lama yang belum
            // punya kolom itu jatuh ke label terbaru unitnya.
            $barcode = $item->packaging_barcode ?? $barcodeByStock[(int) $item->instrument_stock_id] ?? null;

            $unit = [
                'id' => $item->id,
                // Nama dari snapshot production_item; relasi master hanya cadangan.
                'name' => $prod?->name ?? $stock?->instrument?->name,
                'unit_code' => $stock?->code,
                'result' => $item->result,
                // Gagal steril — sumbernya kolom `disabled` baris ini.
                'failed' => (bool) $item->disabled,
            ];

            // Satu label = satu bungkus fisik pada satu batch. Unit tanpa label
            // dikunci per baris sterilisasi agar tidak pernah lebur dengan unit lain.
            $key = $barcode === null
                ? 'nolabel|'.$item->id
                : 'label|'.$batch?->id.'|'.$barcode;

            $groups[$key] ??= [
                'key' => $key,
                'barcode_no' => $barcode,
                // Nama baris: nama paket bila unitnya memang bagian dari sebuah paket,
                // selain itu nama instrumennya sendiri.
                'name' => $prod?->package_name
                    ?? (($oi?->source) === 'paket' ? $oi?->package_name : null)
                    ?? $unit['name'],
                'batch_code' => $batch?->code,
                'method' => $batch?->method,
                'machine' => $batch?->machine,
                'cycle_number' => $batch?->cycle_number,
                'temperature' => $batch?->temperature,
                'duration_minutes' => $batch?->duration_minutes,
                'operator' => $batch?->operator,
                'sterilized_at' => $batch?->sterilized_at,
                // Hasil validasi batch: indikator kimia + indikator biologi
                // (pembanding/kontrol & uji). Bernilai null pada batch yang belum
                // divalidasi.
                'chemical_indicator' => $batch?->chemical_indicator,
                'bio_indicator_control' => $batch?->bio_indicator_control,
                'bio_indicator_test' => $batch?->bio_indicator_test,
                'expiry_date' => $batch?->expiry_date,
                'qty' => 0,
                // Satu bungkus bisa berisi campuran hasil (mis. satu unit gagal, sisanya
                // lulus); baris ditandai gagal bila ADA unit yang gagal — supaya
                // bungkus bermasalah tidak pernah terlihat aman.
                'failed' => false,
                'units' => [],
            ];
            $groups[$key]['qty']++;
            $groups[$key]['failed'] = $groups[$key]['failed'] || $unit['failed'];
            $groups[$key]['units'][] = $unit;
        }

        // Baris berisi satu unit: kode unitnya dipakai sebagai identitas baris. Baris
        // gabungan tidak punya satu kode unit — kodenya ada di detail tiap unit.
        foreach ($groups as $key => $group) {
            $groups[$key]['unit_code'] = $group['qty'] === 1 ? $group['units'][0]['unit_code'] : null;
        }

        $all = array_values($groups);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator($slice, count($all), $perPage, $page);

        return $this->success('Laporan CSSD per alat berhasil diambil.', $paginator);
    }

    /**
     * Pilihan filter MESIN untuk laporan CSSD per alat: daftar mesin yang benar-benar
     * pernah dipakai pada batch sterilisasi.
     *
     * Sengaja TIDAK diambil dari master `sterilizer_machines`: kolom
     * `sterilizations.machine` menyimpan NAMA mesin sebagai teks (snapshot saat batch
     * dijalankan), jadi mesin yang sudah dinonaktifkan atau di-rename di master tidak
     * akan cocok — padahal batch lamanya masih ada di laporan. Diambil dari datanya
     * sendiri, pilihannya selalu sama dengan isi laporan.
     */
    public function cssdMachines(): JsonResponse
    {
        $machines = Sterilization::query()
            ->whereNotNull('machine')
            ->where('machine', '!=', '')
            ->distinct()
            ->orderBy('machine')
            ->pluck('machine')
            ->values();

        return $this->success('Daftar mesin sterilisasi berhasil diambil.', $machines);
    }

    /**
     * Nomor label kemasan (`packaging_item.barcode_no`) TERBARU tiap unit, di-key oleh
     * instrument_stock_id. Label yang sudah di-void (`disabled`) diabaikan; satu unit
     * bisa punya beberapa label lintas siklus, jadi yang diambil paling akhir.
     *
     * Aturannya sama dengan MonitoringController::barcodeNoByStock — kalau salah satu
     * berubah, ubah juga yang lain agar label di laporan & monitoring tidak berbeda.
     *
     * @param  array<int,int>  $stockIds
     * @return array<int,string>
     */
    private function barcodeNoByStock(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return PackagingItem::whereIn('instrument_stock_id', $stockIds)
            ->where('disabled', false)
            ->whereNotNull('barcode_no')
            ->orderByDesc('id')
            ->get(['instrument_stock_id', 'barcode_no'])
            ->groupBy('instrument_stock_id')
            ->map(fn ($g) => $g->first()->barcode_no) // orderByDesc → first = terbaru
            ->all();
    }
}
