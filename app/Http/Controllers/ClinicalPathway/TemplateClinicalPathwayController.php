<?php

namespace App\Http\Controllers\ClinicalPathway;

use App\Http\Controllers\Controller;
use App\Models\TemplateClinicalPathway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateClinicalPathwayController extends Controller
{
    /** Ambil daftar template clinical pathway (cari berdasarkan deskripsi / kode ICD 10). */
    public function index(Request $request): JsonResponse
    {
        $data = TemplateClinicalPathway::with('icd10')
            ->withCount('points')
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('description', 'like', "%{$s}%")
                    ->orWhereHas('icd10', fn ($w) => $w->where('code', 'like', "%{$s}%")
                        ->orWhere('display', 'like', "%{$s}%"))
            )
            ->latest()
            ->paginate(20);

        return $this->success('Data template clinical pathway berhasil diambil.', $data);
    }

    /** Simpan template baru beserta diagnosa ICD 10 & jumlah hari maksimal. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'icd10_id' => 'required|integer|exists:icd10,id',
            'max_days' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            $template = TemplateClinicalPathway::create($validated);

            return $this->success('Template clinical pathway berhasil ditambahkan.', $template->load('icd10'), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Tampilkan detail template beserta diagnosanya. */
    public function show(TemplateClinicalPathway $template): JsonResponse
    {
        return $this->success('Detail template clinical pathway berhasil diambil.', $template->load('icd10'));
    }

    /** Perbarui data template clinical pathway. */
    public function update(Request $request, TemplateClinicalPathway $template): JsonResponse
    {
        $validated = $request->validate([
            'icd10_id' => 'required|integer|exists:icd10,id',
            'max_days' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            $template->update($validated);

            return $this->success('Template clinical pathway berhasil diperbarui.', $template->load('icd10'));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Aktif / non-aktifkan template. Template clinical pathway tidak bisa dihapus,
     * hanya di-toggle statusnya.
     */
    public function toggleStatus(TemplateClinicalPathway $template): JsonResponse
    {
        try {
            $template->update(['is_active' => ! $template->is_active]);

            return $this->success(
                $template->is_active ? 'Template diaktifkan.' : 'Template dinonaktifkan.',
                $template->load('icd10'),
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
