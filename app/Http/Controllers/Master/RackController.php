<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Rack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master rak gudang steril CSSD. CRUD sederhana (nama + keterangan) + endpoint
 * `options` untuk mengisi pilihan lokasi rak pada alur "Simpan ke Gudang".
 */
class RackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Rack::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('note', 'like', "%{$s}%"))
        )
            ->latest()
            ->paginate(20);

        return $this->success('Data rak berhasil diambil.', $data);
    }

    /** Daftar ringkas semua rak (id + nama) untuk pilihan dropdown. */
    public function options(): JsonResponse
    {
        $data = Rack::orderBy('name')->get(['id', 'name']);

        return $this->success('Daftar rak berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $rack = Rack::create($validated);

            return $this->success('Rak berhasil ditambahkan.', $rack, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Rack $rack): JsonResponse
    {
        return $this->success('Detail rak berhasil diambil.', $rack);
    }

    public function update(Request $request, Rack $rack): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $rack->update($validated);

            return $this->success('Rak berhasil diperbarui.', $rack);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Rack $rack): JsonResponse
    {
        try {
            $rack->delete();

            return $this->success('Rak berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'note' => 'nullable|string',
        ]);
    }
}
