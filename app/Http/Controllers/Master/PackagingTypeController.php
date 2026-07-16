<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\PackagingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master jenis kemasan (linen, pouch, container, ...) — tahap Packaging. CRUD
 * standar; jenis berstatus aktif dipakai sebagai pilihan dropdown saat "Selesai
 * Pengemasan", dan masa simpannya menentukan tgl kedaluwarsa steril batch.
 */
class PackagingTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = PackagingType::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%"))
        )
            ->latest()
            ->paginate(20);

        return $this->success('Data jenis kemasan berhasil diambil.', $data);
    }

    /**
     * Pilihan jenis kemasan untuk dropdown "Selesai Pengemasan", tanpa paginasi.
     * `shelf_life_days` ikut dikirim agar FE bisa menampilkan pratinjau tgl
     * kedaluwarsa tanpa menyalin angkanya. Untuk menyembunyikan sebuah jenis dari
     * dropdown, hapus jenis tersebut (soft delete) — tidak ada status aktif/nonaktif.
     */
    public function options(): JsonResponse
    {
        $data = PackagingType::orderBy('name')
            ->get()
            ->map(fn (PackagingType $t) => [
                'value' => $t->id,
                'label' => $t->name,
                'shelf_life_days' => $t->shelf_life_days,
            ]);

        return $this->success('Daftar jenis kemasan berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $type = PackagingType::create($validated);

            return $this->success('Jenis kemasan berhasil ditambahkan.', $type, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(PackagingType $packaging_type): JsonResponse
    {
        return $this->success('Detail jenis kemasan berhasil diambil.', $packaging_type);
    }

    public function update(Request $request, PackagingType $packaging_type): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $packaging_type->update($validated);

            return $this->success('Jenis kemasan berhasil diperbarui.', $packaging_type);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(PackagingType $packaging_type): JsonResponse
    {
        try {
            $packaging_type->delete();

            return $this->success('Jenis kemasan berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            // Masa simpan steril (hari) — menentukan tgl kedaluwarsa batch yang
            // memakai jenis kemasan ini.
            'shelf_life_days' => 'required|integer|min:1',
            'note' => 'nullable|string',
        ]);
    }
}
