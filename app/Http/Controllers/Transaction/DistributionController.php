<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Bmhp;
use App\Models\Distribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DistributionController extends Controller
{
    /** Relasi yang dimuat saat menampilkan detail distribusi. */
    private const DETAIL_RELATIONS = [
        'room',
        'sender',
        'receiver',
        'items.bmhp',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = Distribution::with(['room', 'sender', 'receiver'])
            ->withCount('items')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($q) => $q->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('receiver', fn ($q) => $q->where('name', 'like', "%{$s}%"))
            )
            ->latest()
            ->paginate(20);

        return $this->success('Data distribusi BMHP berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'receiver_id' => 'required|integer|exists:users,id',
            'distributed_at' => 'sometimes|nullable|date',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.bmhp_id' => 'required|integer|exists:bmhps,id',
            'items.*.quantity' => 'sometimes|integer|min:1',
            'items.*.note' => 'nullable|string',
        ]);

        try {
            $distribution = DB::transaction(function () use ($validated) {
                // Buat header distribusi.
                $distribution = Distribution::create([
                    'room_id' => $validated['room_id'],
                    'sender_id' => auth()->id(),
                    'receiver_id' => $validated['receiver_id'],
                    'distributed_at' => $validated['distributed_at'] ?? now(),
                    'status' => Distribution::STATUS_TERDISTRIBUSI,
                    'note' => $validated['note'] ?? null,
                ]);

                // Buat item + kurangi stok BMHP.
                foreach ($validated['items'] as $item) {
                    $qty = $item['quantity'] ?? 1;

                    $bmhp = Bmhp::find($item['bmhp_id']);
                    if ($bmhp->stock_qty < $qty) {
                        throw ValidationException::withMessages([
                            'items' => ["Stok BMHP {$bmhp->name} tidak mencukupi (tersisa {$bmhp->stock_qty})."],
                        ]);
                    }
                    $bmhp->decrement('stock_qty', $qty);

                    $distribution->items()->create([
                        'bmhp_id' => $item['bmhp_id'],
                        'quantity' => $qty,
                        'note' => $item['note'] ?? null,
                    ]);
                }

                return $distribution;
            });

            $distribution->load(self::DETAIL_RELATIONS);

            return $this->success('Distribusi BMHP berhasil dibuat.', $distribution, 201);
        } catch (ValidationException $e) {
            throw $e; // biar ditangani handler global → 422
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Distribution $distribution): JsonResponse
    {
        $distribution->load(self::DETAIL_RELATIONS);

        return $this->success('Detail distribusi berhasil diambil.', $distribution);
    }

    public function update(Request $request, Distribution $distribution): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Distribution::STATUSES)],
            'note' => 'sometimes|nullable|string',
        ]);

        try {
            DB::transaction(function () use ($validated, $distribution) {
                // Pembatalan → kembalikan stok.
                if (
                    isset($validated['status'])
                    && $validated['status'] === Distribution::STATUS_DIBATALKAN
                    && $distribution->status !== Distribution::STATUS_DIBATALKAN
                ) {
                    $this->restoreStock($distribution);
                }

                $distribution->fill($validated);
                $distribution->save();
            });

            $distribution->load(self::DETAIL_RELATIONS);

            return $this->success('Distribusi berhasil diperbarui.', $distribution);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Distribution $distribution): JsonResponse
    {
        try {
            DB::transaction(function () use ($distribution) {
                // Kembalikan stok bila distribusi masih aktif (belum dibatalkan).
                if ($distribution->status !== Distribution::STATUS_DIBATALKAN) {
                    $this->restoreStock($distribution);
                }

                $distribution->delete();
            });

            return $this->success('Distribusi berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Kembalikan stok BMHP yang sempat dipakai distribusi.
     */
    private function restoreStock(Distribution $distribution): void
    {
        foreach ($distribution->items()->get() as $item) {
            if ($item->bmhp_id) {
                Bmhp::where('id', $item->bmhp_id)->increment('stock_qty', $item->quantity);
            }
        }
    }
}
