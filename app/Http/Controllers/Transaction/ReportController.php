<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Laporan CSSD Per Alat: satu baris per unit instrumen di setiap batch sterilisasi.
     * Sumber data = SterilizationItem → Sterilization + InstrumentStock (instrument & condition).
     *
     * Filter: ?search (nama/kode alat), ?status, ?method, ?date_from, ?date_to (tanggal sterilisasi).
     * ?per_page boleh dipakai untuk export (default 20, maks 2000).
     */
    public function cssdPerItem(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(Sterilization::STATUSES)],
            'method' => ['nullable', Rule::in(Sterilization::METHODS)],
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
                    ->when($request->date_from, fn ($q, $d) => $q->whereDate('sterilized_at', '>=', $d))
                    ->when($request->date_to, fn ($q, $d) => $q->whereDate('sterilized_at', '<=', $d));
            })
            ->when($request->search, fn ($q, $s) => $q->whereHas('instrumentStock', function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('instrument', fn ($q) => $q->where('name', 'like', "%{$s}%"));
            }))
            ->with(['sterilization', 'instrumentStock.instrument', 'instrumentStock.condition'])
            ->latest()
            ->get();

        // Asal (satuan/paket) + nama paket per unit diambil dari order_item batch
        // tersebut: kunci (order_id, instrument_stock_id).
        $orderIds = $items->pluck('sterilization.order_id')->filter()->unique()->all();
        $orderItems = OrderItem::whereIn('order_id', $orderIds)
            ->get(['order_id', 'instrument_stock_id', 'source', 'package_name'])
            ->keyBy(fn ($oi) => $oi->order_id.'-'.$oi->instrument_stock_id);

        $groups = [];
        foreach ($items as $item) {
            $batch = $item->sterilization;
            $stock = $item->instrumentStock;
            $oi = $orderItems->get($batch?->order_id.'-'.$item->instrument_stock_id);
            $isPaket = ($oi?->source) === 'paket';

            $unit = [
                'id' => $item->id,
                'name' => $stock?->instrument?->name,
                'unit_code' => $stock?->code,
                'condition' => $stock?->condition?->name,
                'result' => $item->result,
            ];

            // Field tingkat-batch yang sama untuk header grup.
            $base = [
                'batch_code' => $batch?->code,
                'status' => $batch?->status,
                'method' => $batch?->method,
                'machine' => $batch?->machine,
                'operator' => $batch?->operator,
                'sterilized_at' => $batch?->sterilized_at,
                'expiry_date' => $batch?->expiry_date,
            ];

            if ($isPaket) {
                $pkg = $oi->package_name ?? 'Paket';
                $key = 'pkg|'.$batch?->id.'|'.$pkg;
                $groups[$key] ??= array_merge($base, [
                    'key' => $key,
                    'type' => 'paket',
                    'name' => $pkg,
                    'unit_code' => null,
                    'condition' => null,
                    'qty' => 0,
                    'units' => [],
                ]);
                $groups[$key]['qty']++;
                $groups[$key]['units'][] = $unit;
            } else {
                $key = 'unit|'.$item->id;
                $groups[$key] = array_merge($base, [
                    'key' => $key,
                    'type' => 'satuan',
                    'name' => $unit['name'],
                    'unit_code' => $unit['unit_code'],
                    'condition' => $unit['condition'],
                    'result' => $item->result,
                    'qty' => 1,
                    'units' => [$unit],
                ]);
            }
        }

        $all = array_values($groups);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator($slice, count($all), $perPage, $page);

        return $this->success('Laporan CSSD per alat berhasil diambil.', $paginator);
    }
}
