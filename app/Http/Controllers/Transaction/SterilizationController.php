<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\Sterilization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SterilizationController extends Controller
{
    /** Relasi yang dimuat saat menampilkan detail batch. */
    private const DETAIL_RELATIONS = [
        'items.instrumentStock.instrument',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = Sterilization::withCount('items')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('code', 'like', "%{$s}%")
                    ->orWhere('machine', 'like', "%{$s}%")
            )
            ->latest()
            ->paginate(20);

        return $this->success('Data sterilisasi berhasil diambil.', $data);
    }

    /**
     * Daftar batch sterilisasi (selesai) yang sterilnya sudah/akan kadaluarsa.
     * ?days= ambang hari ke depan (default 7). Termasuk yang sudah lewat.
     */
    public function expiring(Request $request): JsonResponse
    {
        $days = max(0, (int) $request->input('days', 7));
        $threshold = now()->addDays($days)->toDateString();

        $data = Sterilization::where('status', Sterilization::STATUS_SELESAI)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $threshold)
            ->with('items.instrumentStock.instrument')
            ->orderBy('expiry_date')
            ->paginate(20);

        return $this->success('Data sterilisasi mendekati/melewati kadaluarsa berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'machine' => 'required|string|max:255',
            'method' => ['nullable', Rule::in(Sterilization::METHODS)],
            'cycle_number' => 'nullable|string|max:100',
            'temperature' => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer|min:0',
            'operator' => 'nullable|string|max:255',
            'sterilized_at' => 'required|date',
            'expiry_date' => 'nullable|date|after_or_equal:sterilized_at',
            'chemical_indicator' => 'nullable|string|max:100',
            'biological_indicator' => 'nullable|string|max:100',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.instrument_stock_id' => 'required|integer|distinct|exists:instrument_stocks,id',
        ]);

        try {
            $sterilization = DB::transaction(function () use ($validated) {
                $sterilization = Sterilization::create([
                    ...collect($validated)->except('items')->all(),
                    'method' => $validated['method'] ?? Sterilization::METHOD_UAP,
                    'status' => Sterilization::STATUS_DIPROSES,
                ]);

                $stockIds = [];
                foreach ($validated['items'] as $item) {
                    $sterilization->items()->create([
                        'instrument_stock_id' => $item['instrument_stock_id'],
                    ]);
                    $stockIds[] = $item['instrument_stock_id'];
                }

                // Unit yang masuk batch → status sterilisasi
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'sterilization',
                    'reference' => $sterilization->code,
                ]);

                return $sterilization;
            });

            $sterilization->load(self::DETAIL_RELATIONS);

            return $this->success('Batch sterilisasi berhasil dibuat.', $sterilization, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Sterilization $sterilization): JsonResponse
    {
        $sterilization->load(self::DETAIL_RELATIONS);

        return $this->success('Detail sterilisasi berhasil diambil.', $sterilization);
    }

    public function update(Request $request, Sterilization $sterilization): JsonResponse
    {
        $validated = $request->validate([
            'machine' => 'sometimes|required|string|max:255',
            'method' => ['sometimes', Rule::in(Sterilization::METHODS)],
            'cycle_number' => 'sometimes|nullable|string|max:100',
            'temperature' => 'sometimes|nullable|numeric',
            'duration_minutes' => 'sometimes|nullable|integer|min:0',
            'operator' => 'sometimes|nullable|string|max:255',
            'sterilized_at' => 'sometimes|required|date',
            'expiry_date' => 'sometimes|nullable|date',
            'chemical_indicator' => 'sometimes|nullable|string|max:100',
            'biological_indicator' => 'sometimes|nullable|string|max:100',
            'note' => 'sometimes|nullable|string',
            'status' => ['sometimes', Rule::in(Sterilization::STATUSES)],
        ]);

        try {
            DB::transaction(function () use ($validated, $sterilization) {
                $sterilization->fill(collect($validated)->except('status')->all());

                if (isset($validated['status'])) {
                    $this->applyStatusTransition($sterilization, $validated['status']);
                }

                $sterilization->save();
            });

            $sterilization->load(self::DETAIL_RELATIONS);

            return $this->success('Batch sterilisasi berhasil diperbarui.', $sterilization);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Sterilization $sterilization): JsonResponse
    {
        try {
            $sterilization->delete();

            return $this->success('Batch sterilisasi berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Terapkan perubahan status batch + sinkronkan status unit instrumen terkait.
     * - selesai  : unit steril & siap pakai → tersedia
     * - gagal    : unit gagal steril, harus diproses ulang → tetap sterilisasi
     * - diproses : unit kembali ke proses → sterilisasi
     */
    private function applyStatusTransition(Sterilization $sterilization, string $status): void
    {
        $sterilization->status = $status;

        // Saat batch dinyatakan selesai & kedaluwarsa belum diisi operator, hitung
        // otomatis = base + batas steril mesin washer (master), fallback default.
        if ($status === Sterilization::STATUS_SELESAI && $sterilization->expiry_date === null) {
            $sterilization->expiry_date = $sterilization->computeExpiryDate();
        }

        $stockIds = $sterilization->items()->pluck('instrument_stock_id');

        $unitStatus = match ($status) {
            Sterilization::STATUS_SELESAI => InstrumentStock::STATUS_TERSEDIA,
            default => InstrumentStock::STATUS_STERILISASI,
        };

        InstrumentStock::transitionMany($stockIds, $unitStatus, [
            'context' => 'sterilization',
            'reference' => $sterilization->code,
        ]);
    }
}
