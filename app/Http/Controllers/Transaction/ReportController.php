<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $data = SterilizationItem::query()
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
            ->paginate($perPage);

        $data->getCollection()->transform(function (SterilizationItem $item) {
            $batch = $item->sterilization;
            $stock = $item->instrumentStock;

            return [
                'id' => $item->id,
                'name' => $stock?->instrument?->name,
                'unit_code' => $stock?->code,
                'batch_code' => $batch?->code,
                'status' => $batch?->status,
                'method' => $batch?->method,
                'machine' => $batch?->machine,
                'operator' => $batch?->operator,
                'condition' => $stock?->condition?->name,
                'result' => $item->result,
                'sterilized_at' => $batch?->sterilized_at,
                'expiry_date' => $batch?->expiry_date,
            ];
        });

        return $this->success('Laporan CSSD per alat berhasil diambil.', $data);
    }
}
