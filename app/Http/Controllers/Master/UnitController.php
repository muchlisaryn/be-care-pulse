<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master satuan (pcs, box, botol, ...). CRUD standar; penghapusan soft delete
 * lewat HasAuditColumns, jadi satuan yang dihapus hilang dari daftar & dropdown
 * tanpa menghapus barisnya.
 */
class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Unit::when(
            $request->search,
            fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
        )
            ->latest()
            ->paginate(20);

        return $this->success('Data satuan berhasil diambil.', $data);
    }

    /** Pilihan satuan untuk dropdown, tanpa paginasi. */
    public function options(): JsonResponse
    {
        $data = Unit::orderBy('name')
            ->get()
            ->map(fn (Unit $u) => [
                'value' => $u->id,
                'label' => $u->name,
            ]);

        return $this->success('Daftar satuan berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $unit = Unit::create($validated);

            return $this->success('Satuan berhasil ditambahkan.', $unit, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Unit $unit): JsonResponse
    {
        return $this->success('Detail satuan berhasil diambil.', $unit);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $validated = $this->validatePayload($request, $unit);

        try {
            $unit->update($validated);

            return $this->success('Satuan berhasil diperbarui.', $unit);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Unit $unit): JsonResponse
    {
        try {
            $unit->delete();

            return $this->success('Satuan berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function validatePayload(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            // Nama unik di antara satuan yang masih aktif — nama milik satuan yang
            // sudah dihapus boleh dipakai lagi.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'name')->whereNull('deleted_by')->ignore($unit?->id),
            ],
            'note' => 'nullable|string',
        ], [
            'name.unique' => 'Nama satuan sudah dipakai.',
        ]);
    }
}
