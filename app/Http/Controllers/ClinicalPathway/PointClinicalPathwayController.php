<?php

namespace App\Http\Controllers\ClinicalPathway;

use App\Http\Controllers\Controller;
use App\Models\PointClinicalPathway;
use App\Models\TemplateClinicalPathway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PointClinicalPathwayController extends Controller
{
    /** Semua poin untuk satu template (flat, urut) — frontend menyusun pohonnya. */
    public function index(TemplateClinicalPathway $template): JsonResponse
    {
        $points = PointClinicalPathway::with('categori')
            ->where('template_id', $template->id)
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();

        return $this->success('Data poin formulir berhasil diambil.', $points);
    }

    /**
     * Salin seluruh poin (beserta sub-poin & hierarkinya) dari satu formulir
     * sumber ke formulir ini. Berguna saat membuat formulir untuk diagnosa baru
     * tanpa menyusun ulang dari awal. Poin sumber ditambahkan (append) ke poin
     * yang sudah ada. `hari_wajib` yang melebihi `maksimal_hari` formulir tujuan
     * otomatis diabaikan.
     */
    public function copyFrom(Request $request, TemplateClinicalPathway $template): JsonResponse
    {
        $validated = $request->validate([
            'source_template_id' => [
                'required',
                'integer',
                'different:'.$template->id,
                Rule::exists('template_clinical_pathway', 'id'),
            ],
        ]);

        try {
            $source = TemplateClinicalPathway::findOrFail($validated['source_template_id']);

            $sourcePoints = PointClinicalPathway::where('template_id', $source->id)
                ->orderBy('urutan')
                ->orderBy('id')
                ->get();

            if ($sourcePoints->isEmpty()) {
                return $this->error('Formulir sumber belum memiliki poin untuk disalin.', 422);
            }

            // Poin sumber dikelompokkan per parent (null → kunci 0) supaya bisa
            // disalin secara rekursif sambil memetakan parent_id ke id baru.
            $byParent = $sourcePoints->groupBy(fn ($p) => $p->parent_id ?? 0);

            // Urutan per grup (categori_id + parent_id baru) dilanjutkan dari poin
            // yang sudah ada di formulir tujuan.
            $counters = [];
            $nextUrutan = function (int $categoriId, ?int $parentId) use (&$counters, $template): int {
                $key = $categoriId.':'.($parentId ?? 'root');
                if (! array_key_exists($key, $counters)) {
                    $counters[$key] = (int) PointClinicalPathway::where('template_id', $template->id)
                        ->where('categori_id', $categoriId)
                        ->where('parent_id', $parentId)
                        ->max('urutan');
                }

                return ++$counters[$key];
            };

            $copied = 0;
            $copyLevel = function (int $sourceParentKey, ?int $newParentId) use (
                &$copyLevel, &$copied, $byParent, $template, $nextUrutan
            ) {
                foreach ($byParent->get($sourceParentKey, collect()) as $src) {
                    $hari = array_values(array_filter(
                        $src->hari_wajib ?? [],
                        fn ($d) => $d >= 1 && $d <= $template->maksimal_hari,
                    ));

                    $new = PointClinicalPathway::create([
                        'template_id' => $template->id,
                        'categori_id' => $src->categori_id,
                        'parent_id' => $newParentId,
                        'label' => $src->label,
                        'pengisi' => $src->pengisi,
                        'hari_wajib' => $hari,
                        'urutan' => $nextUrutan($src->categori_id, $newParentId),
                    ]);
                    $copied++;

                    // Salin sub-poin di bawah poin sumber ini.
                    $copyLevel($src->id, $new->id);
                }
            };

            DB::transaction(fn () => $copyLevel(0, null));

            $points = PointClinicalPathway::with('categori')
                ->where('template_id', $template->id)
                ->orderBy('urutan')
                ->orderBy('id')
                ->get();

            return $this->success("Berhasil menyalin {$copied} poin dari formulir sumber.", $points);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function store(Request $request, TemplateClinicalPathway $template): JsonResponse
    {
        $validated = $this->validatePoint($request, $template);

        try {
            // Urutan = jumlah saudara (parent + kategori sama) + 1.
            $urutan = PointClinicalPathway::where('template_id', $template->id)
                ->where('categori_id', $validated['categori_id'])
                ->where('parent_id', $validated['parent_id'] ?? null)
                ->max('urutan');

            // Sub-poin selalu mengikuti pengisi induknya (tidak bisa beda).
            $parentId = $validated['parent_id'] ?? null;
            $pengisi = $validated['pengisi'];
            if ($parentId) {
                $pengisi = PointClinicalPathway::find($parentId)?->pengisi ?? $pengisi;
            }

            $point = PointClinicalPathway::create([
                'template_id' => $template->id,
                'categori_id' => $validated['categori_id'],
                'parent_id' => $parentId,
                'label' => $validated['label'],
                'pengisi' => $pengisi,
                'hari_wajib' => $validated['hari_wajib'] ?? [],
                'urutan' => (int) $urutan + 1,
            ]);

            return $this->success('Poin berhasil ditambahkan.', $point->load('categori'), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function update(Request $request, PointClinicalPathway $point): JsonResponse
    {
        $validated = $this->validatePoint($request, $point->template, $point);

        try {
            // Sub-poin mengikuti pengisi induk; poin level atas pakai input user.
            $pengisi = $point->parent_id
                ? (PointClinicalPathway::find($point->parent_id)?->pengisi ?? $validated['pengisi'])
                : $validated['pengisi'];

            $point->update([
                'label' => $validated['label'],
                'pengisi' => $pengisi,
                'hari_wajib' => $validated['hari_wajib'] ?? [],
            ]);

            // Pengisi induk berubah → seluruh keturunan ikut menyesuaikan.
            $this->cascadePengisi($point);

            return $this->success('Poin berhasil diperbarui.', $point->load('categori'));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Samakan pengisi seluruh keturunan poin dengan pengisi poin ini. */
    private function cascadePengisi(PointClinicalPathway $point): void
    {
        foreach ($point->children as $child) {
            if ($child->pengisi !== $point->pengisi) {
                $child->update(['pengisi' => $point->pengisi]);
            }
            $this->cascadePengisi($child);
        }
    }

    /** Hapus poin beserta seluruh sub-poinnya (rekursif). */
    public function destroy(PointClinicalPathway $point): JsonResponse
    {
        try {
            $this->deleteWithChildren($point);

            return $this->success('Poin berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function deleteWithChildren(PointClinicalPathway $point): void
    {
        foreach ($point->children as $child) {
            $this->deleteWithChildren($child);
        }
        $point->delete();
    }

    /** Validasi poin. hari_wajib dibatasi 1..maksimal_hari milik template. */
    private function validatePoint(Request $request, TemplateClinicalPathway $template, ?PointClinicalPathway $point = null): array
    {
        return $request->validate([
            'categori_id' => 'required|integer|exists:categori_clinical_pathway,id',
            // parent_id (untuk sub-poin) harus poin lain di template yang sama.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('point_clinical_pathway', 'id')->where('template_id', $template->id),
            ],
            'label' => 'required|string|max:255',
            'pengisi' => ['required', Rule::in(PointClinicalPathway::PENGISI)],
            'hari_wajib' => 'nullable|array',
            'hari_wajib.*' => 'integer|min:1|max:'.$template->maksimal_hari,
        ]);
    }
}
