<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\WasherMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master mesin pencuci (washer disinfector) — tahap Cleaning & Disinfection.
 * Menyediakan CRUD + endpoint scan barcode (lookup mesin via kode).
 */
class WasherMachineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = WasherMachine::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('location', 'like', "%{$s}%"))
        )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return $this->success('Data mesin washer berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $machine = WasherMachine::create($validated);

            return $this->success('Mesin washer berhasil ditambahkan.', $machine, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(WasherMachine $washer_machine): JsonResponse
    {
        return $this->success('Detail mesin washer berhasil diambil.', $washer_machine);
    }

    public function update(Request $request, WasherMachine $washer_machine): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $washer_machine->update($validated);

            return $this->success('Mesin washer berhasil diperbarui.', $washer_machine);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(WasherMachine $washer_machine): JsonResponse
    {
        try {
            $washer_machine->delete();

            return $this->success('Mesin washer berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Scan barcode mesin washer: lookup mesin berdasarkan kode (WSH-NNN).
     * Dipakai petugas sebelum memasukkan alat ke mesin pencuci.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $machine = WasherMachine::where('code', $validated['code'])->first();

        if (! $machine) {
            return $this->error('Mesin washer dengan kode tersebut tidak ditemukan.', 404);
        }

        if ($machine->status !== WasherMachine::STATUS_AKTIF) {
            return $this->error('Mesin washer ini berstatus nonaktif dan tidak dapat digunakan.', 422);
        }

        return $this->success('Mesin washer ditemukan.', $machine);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'min_temperature' => 'nullable|numeric',
            // Batas atas hanya wajib >= batas bawah bila batas bawah memang diisi.
            // Tanpa syarat ini, mengisi max saja (min kosong) memicu 422 karena
            // `gte` membandingkan dengan nilai null.
            'max_temperature' => ['nullable', 'numeric', Rule::when($request->filled('min_temperature'), 'gte:min_temperature')],
            'min_duration_minutes' => 'nullable|integer|min:0',
            'max_duration_minutes' => ['nullable', 'integer', 'min:0', Rule::when($request->filled('min_duration_minutes'), 'gte:min_duration_minutes')],
            // Batas steril: masa simpan steril (hari) untuk alat yang dicuci di mesin ini.
            'sterile_shelf_life_days' => 'nullable|integer|min:1',
            'status' => 'nullable|in:aktif,nonaktif',
            'note' => 'nullable|string',
        ]);
    }
}
