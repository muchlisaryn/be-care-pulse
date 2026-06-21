<?php

namespace App\Http\Controllers\ClinicalPathway;

use App\Http\Controllers\Controller;
use App\Models\CategoriClinicalPathway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriClinicalPathwayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = CategoriClinicalPathway::when(
            $request->search,
            fn ($q, $s) => $q->where('label', 'like', "%{$s}%")
                ->orWhere('urutan', 'like', "%{$s}%")
        )
            ->orderBy('urutan')
            ->paginate(20);

        return $this->success('Data kategori clinical pathway berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'urutan' => 'required|integer|min:1|unique:categori_clinical_pathway,urutan',
            'label' => 'required|string|max:255',
        ]);

        try {
            $categori = CategoriClinicalPathway::create($validated);

            return $this->success('Kategori clinical pathway berhasil ditambahkan.', $categori, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(CategoriClinicalPathway $categori): JsonResponse
    {
        return $this->success('Detail kategori clinical pathway berhasil diambil.', $categori);
    }

    public function update(Request $request, CategoriClinicalPathway $categori): JsonResponse
    {
        $validated = $request->validate([
            'urutan' => 'required|integer|min:1|unique:categori_clinical_pathway,urutan,'.$categori->id,
            'label' => 'required|string|max:255',
        ]);

        try {
            $categori->update($validated);

            return $this->success('Kategori clinical pathway berhasil diperbarui.', $categori);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

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
