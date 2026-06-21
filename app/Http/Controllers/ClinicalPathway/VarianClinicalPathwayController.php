<?php

namespace App\Http\Controllers\ClinicalPathway;

use App\Http\Controllers\Controller;
use App\Models\AsesmenClinicalPathway;
use App\Models\VarianClinicalPathway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VarianClinicalPathwayController extends Controller
{
    /** Semua catatan varian untuk satu asesmen (terbaru di atas). */
    public function index(Request $request, AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $data = VarianClinicalPathway::where('asesmen_id', $asesmen->id)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('varian', 'like', "%{$s}%")
                    ->orWhere('alasan', 'like', "%{$s}%")
            )
            ->orderByDesc('tanggal_waktu')
            ->orderByDesc('id')
            ->get();

        return $this->success('Data varian clinical pathway berhasil diambil.', $data);
    }

    public function store(Request $request, AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $validated = $this->validateVarian($request);

        try {
            $varian = VarianClinicalPathway::create([
                'asesmen_id' => $asesmen->id,
                'tanggal_waktu' => $validated['tanggal_waktu'],
                'varian' => $validated['varian'],
                'alasan' => $validated['alasan'] ?? null,
                // Paraf selalu diisi otomatis dari username user yang login.
                'paraf' => $request->user()->username,
            ]);

            return $this->success('Catatan varian berhasil ditambahkan.', $varian, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function update(Request $request, VarianClinicalPathway $varian): JsonResponse
    {
        $validated = $this->validateVarian($request);

        try {
            $varian->update([
                'tanggal_waktu' => $validated['tanggal_waktu'],
                'varian' => $validated['varian'],
                'alasan' => $validated['alasan'] ?? null,
                // Paraf diperbarui ke username user yang melakukan perubahan.
                'paraf' => $request->user()->username,
            ]);

            return $this->success('Catatan varian berhasil diperbarui.', $varian);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(VarianClinicalPathway $varian): JsonResponse
    {
        try {
            $varian->delete();

            return $this->success('Catatan varian berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function validateVarian(Request $request): array
    {
        return $request->validate([
            'tanggal_waktu' => 'required|date',
            'varian' => 'required|string',
            'alasan' => 'nullable|string',
        ]);
    }
}
