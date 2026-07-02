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
        $data = VarianClinicalPathway::where('assessment_id', $asesmen->id)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('variance', 'like', "%{$s}%")
                    ->orWhere('reason', 'like', "%{$s}%")
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return $this->success('Data varian clinical pathway berhasil diambil.', $data);
    }

    /** Tambah catatan varian pada asesmen. Paraf diisi otomatis dari user login. */
    public function store(Request $request, AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $validated = $this->validateVarian($request);

        try {
            $varian = VarianClinicalPathway::create([
                'assessment_id' => $asesmen->id,
                'occurred_at' => $validated['occurred_at'],
                'variance' => $validated['variance'],
                'reason' => $validated['reason'] ?? null,
                // Paraf selalu diisi otomatis dari username user yang login.
                'initials' => $request->user()->username,
            ]);

            return $this->success('Catatan varian berhasil ditambahkan.', $varian, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Perbarui catatan varian. Paraf diperbarui ke username user yang mengubah. */
    public function update(Request $request, VarianClinicalPathway $varian): JsonResponse
    {
        $validated = $this->validateVarian($request);

        try {
            $varian->update([
                'occurred_at' => $validated['occurred_at'],
                'variance' => $validated['variance'],
                'reason' => $validated['reason'] ?? null,
                // Paraf diperbarui ke username user yang melakukan perubahan.
                'initials' => $request->user()->username,
            ]);

            return $this->success('Catatan varian berhasil diperbarui.', $varian);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Hapus catatan varian. */
    public function destroy(VarianClinicalPathway $varian): JsonResponse
    {
        try {
            $varian->delete();

            return $this->success('Catatan varian berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Validasi payload catatan varian (tanggal-waktu, isi varian, alasan). */
    private function validateVarian(Request $request): array
    {
        return $request->validate([
            'occurred_at' => 'required|date',
            'variance' => 'required|string',
            'reason' => 'nullable|string',
        ]);
    }
}
