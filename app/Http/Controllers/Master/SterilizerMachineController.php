<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\SterilizerMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master mesin sterilisator (autoclave) — tahap Sterilization. CRUD standar; daftar
 * mesin aktif dipakai sebagai pilihan dropdown saat menjalankan batch sterilisasi.
 */
class SterilizerMachineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = SterilizerMachine::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('location', 'like', "%{$s}%"))
        )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return $this->success('Data mesin sterilisator berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $machine = SterilizerMachine::create($validated);

            return $this->success('Mesin sterilisator berhasil ditambahkan.', $machine, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(SterilizerMachine $sterilizer_machine): JsonResponse
    {
        return $this->success('Detail mesin sterilisator berhasil diambil.', $sterilizer_machine);
    }

    public function update(Request $request, SterilizerMachine $sterilizer_machine): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $sterilizer_machine->update($validated);

            return $this->success('Mesin sterilisator berhasil diperbarui.', $sterilizer_machine);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(SterilizerMachine $sterilizer_machine): JsonResponse
    {
        try {
            $sterilizer_machine->delete();

            return $this->success('Mesin sterilisator berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            // Suhu & durasi standar mesin.
            'temperature' => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer|min:0',
            // Masa simpan steril (hari) untuk alat yang disterilkan di mesin ini.
            'sterile_shelf_life_days' => 'nullable|integer|min:1',
            'status' => 'nullable|in:aktif,nonaktif',
            'note' => 'nullable|string',
        ]);
    }
}
