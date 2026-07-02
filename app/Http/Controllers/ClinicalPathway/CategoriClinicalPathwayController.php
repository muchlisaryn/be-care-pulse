<?php

namespace App\Http\Controllers\ClinicalPathway;

use App\Http\Controllers\Controller;
use App\Models\CategoriClinicalPathway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriClinicalPathwayController extends Controller
{
    /** Ambil daftar kategori clinical pathway (paginasi + pencarian label/urutan). */
    public function index(Request $request): JsonResponse
    {
        $data = CategoriClinicalPathway::when(
            $request->search,
            fn ($q, $s) => $q->where('label', 'like', "%{$s}%")
                ->orWhere('sort_order', 'like', "%{$s}%")
        )
            ->orderBy('sort_order')
            ->paginate(20);

        return $this->success('Data kategori clinical pathway berhasil diambil.', $data);
    }

    /** Simpan kategori baru (urutan wajib unik). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sort_order' => 'required|integer|min:1|unique:clinical_pathway_categories,sort_order',
            'label' => 'required|string|max:255',
        ]);

        try {
            $categori = CategoriClinicalPathway::create($validated);

            return $this->success('Kategori clinical pathway berhasil ditambahkan.', $categori, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Tampilkan detail satu kategori. */
    public function show(CategoriClinicalPathway $categori): JsonResponse
    {
        return $this->success('Detail kategori clinical pathway berhasil diambil.', $categori);
    }

    /** Perbarui kategori (urutan tetap harus unik, kecuali dirinya sendiri). */
    public function update(Request $request, CategoriClinicalPathway $categori): JsonResponse
    {
        $validated = $request->validate([
            'sort_order' => 'required|integer|min:1|unique:clinical_pathway_categories,sort_order,'.$categori->id,
            'label' => 'required|string|max:255',
        ]);

        try {
            $categori->update($validated);

            return $this->success('Kategori clinical pathway berhasil diperbarui.', $categori);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Hapus kategori clinical pathway. */
    public function destroy(CategoriClinicalPathway $categori): JsonResponse
    {
        try {
            $categori->delete();

            return $this->success('Kategori clinical pathway berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
