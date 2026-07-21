<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\WasherMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master mesin pencuci (washer disinfector) — tahap Cleaning & Disinfection.
 * Menyediakan CRUD + endpoint lookup mesin via id (kode/barcode sudah dihapus).
 */
class WasherMachineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = WasherMachine::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
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
     * Lookup mesin washer berdasarkan id (`washer_machine_id`). Menggantikan scan
     * barcode lama yang mencari lewat kode WSH-NNN — kolom kode sudah dihapus.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'washer_machine_id' => 'required|integer',
        ]);

        $machine = WasherMachine::find($validated['washer_machine_id']);

        if (! $machine) {
            return $this->error('Mesin washer tidak ditemukan.', 404);
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
            // Suhu & durasi standar mesin (batas minimum untuk deteksi kegagalan).
            'temperature' => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer|min:0',
            'status' => 'nullable|in:aktif,nonaktif',
            'note' => 'nullable|string',
        ]);
    }
}
