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
        $points = PointClinicalPathway::with('category')
            ->where('template_id', $template->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success('Data poin formulir berhasil diambil.', $points);
    }

    /**
     * Salin seluruh poin (beserta sub-poin & hierarkinya) dari satu formulir
     * sumber ke formulir ini. Berguna saat membuat formulir untuk diagnosa baru
     * tanpa menyusun ulang dari awal. Poin sumber ditambahkan (append) ke poin
     * yang sudah ada. `required_days` yang melebihi `max_days` formulir tujuan
     * otomatis diabaikan.
     */
    public function copyFrom(Request $request, TemplateClinicalPathway $template): JsonResponse
    {
        $validated = $request->validate([
            'source_template_id' => [
                'required',
                'integer',
                'different:'.$template->id,
                Rule::exists('clinical_pathway_templates', 'id'),
            ],
        ]);

        try {
            $source = TemplateClinicalPathway::findOrFail($validated['source_template_id']);

            $sourcePoints = PointClinicalPathway::where('template_id', $source->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($sourcePoints->isEmpty()) {
                return $this->error('Formulir sumber belum memiliki poin untuk disalin.', 422);
            }

            // Poin sumber dikelompokkan per parent (null → kunci 0) supaya bisa
            // disalin secara rekursif sambil memetakan parent_id ke id baru.
            $byParent = $sourcePoints->groupBy(fn ($p) => $p->parent_id ?? 0);

            // Urutan per grup (category_id + parent_id baru) dilanjutkan dari poin
            // yang sudah ada di formulir tujuan.
            $counters = [];
            $nextSortOrder = function (int $categoryId, ?int $parentId) use (&$counters, $template): int {
                $key = $categoryId.':'.($parentId ?? 'root');
                if (! array_key_exists($key, $counters)) {
                    $counters[$key] = (int) PointClinicalPathway::where('template_id', $template->id)
                        ->where('category_id', $categoryId)
                        ->where('parent_id', $parentId)
                        ->max('sort_order');
                }

                return ++$counters[$key];
            };

            $copied = 0;
            $copyLevel = function (int $sourceParentKey, ?int $newParentId) use (
                &$copyLevel, &$copied, $byParent, $template, $nextSortOrder
            ) {
                foreach ($byParent->get($sourceParentKey, collect()) as $src) {
                    $days = array_values(array_filter(
                        $src->required_days ?? [],
                        fn ($d) => $d >= 1 && $d <= $template->max_days,
                    ));

                    $new = PointClinicalPathway::create([
                        'template_id' => $template->id,
                        'category_id' => $src->category_id,
                        'parent_id' => $newParentId,
                        'label' => $src->label,
                        'filled_by' => $src->filled_by,
                        'required_days' => $days,
                        'sort_order' => $nextSortOrder($src->category_id, $newParentId),
                    ]);
                    $copied++;

                    // Salin sub-poin di bawah poin sumber ini.
                    $copyLevel($src->id, $new->id);
                }
            };

            DB::transaction(fn () => $copyLevel(0, null));

            $points = PointClinicalPathway::with('category')
                ->where('template_id', $template->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return $this->success("Berhasil menyalin {$copied} poin dari formulir sumber.", $points);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Tambah poin baru pada template. Sub-poin selalu ikut pengisi induknya. */
    public function store(Request $request, TemplateClinicalPathway $template): JsonResponse
    {
        $validated = $this->validatePoint($request, $template);

        try {
            // Urutan = jumlah saudara (parent + kategori sama) + 1.
            $sortOrder = PointClinicalPathway::where('template_id', $template->id)
                ->where('category_id', $validated['category_id'])
                ->where('parent_id', $validated['parent_id'] ?? null)
                ->max('sort_order');

            // Sub-poin selalu mengikuti pengisi induknya (tidak bisa beda).
            $parentId = $validated['parent_id'] ?? null;
            $filledBy = $validated['filled_by'];
            if ($parentId) {
                $filledBy = PointClinicalPathway::find($parentId)?->filled_by ?? $filledBy;
            }

            $point = PointClinicalPathway::create([
                'template_id' => $template->id,
                'category_id' => $validated['category_id'],
                'parent_id' => $parentId,
                'label' => $validated['label'],
                'filled_by' => $filledBy,
                'required_days' => $validated['required_days'] ?? [],
                'sort_order' => (int) $sortOrder + 1,
            ]);

            return $this->success('Poin berhasil ditambahkan.', $point->load('category'), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Perbarui poin. Perubahan pengisi otomatis diturunkan ke seluruh sub-poin. */
    public function update(Request $request, PointClinicalPathway $point): JsonResponse
    {
        $validated = $this->validatePoint($request, $point->template, $point);

        try {
            // Sub-poin mengikuti pengisi induk; poin level atas pakai input user.
            $filledBy = $point->parent_id
                ? (PointClinicalPathway::find($point->parent_id)?->filled_by ?? $validated['filled_by'])
                : $validated['filled_by'];

            $point->update([
                'label' => $validated['label'],
                'filled_by' => $filledBy,
                'required_days' => $validated['required_days'] ?? [],
            ]);

            // Pengisi induk berubah → seluruh keturunan ikut menyesuaikan.
            $this->cascadeFilledBy($point);

            return $this->success('Poin berhasil diperbarui.', $point->load('category'));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Samakan pengisi (filled_by) seluruh keturunan poin dengan pengisi poin ini. */
    private function cascadeFilledBy(PointClinicalPathway $point): void
    {
        foreach ($point->children as $child) {
            if ($child->filled_by !== $point->filled_by) {
                $child->update(['filled_by' => $point->filled_by]);
            }
            $this->cascadeFilledBy($child);
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

    /** Hapus poin beserta anak-anaknya secara rekursif (dari daun ke akar). */
    private function deleteWithChildren(PointClinicalPathway $point): void
    {
        foreach ($point->children as $child) {
            $this->deleteWithChildren($child);
        }
        $point->delete();
    }

    /** Validasi poin. required_days dibatasi 1..max_days milik template. */
    private function validatePoint(Request $request, TemplateClinicalPathway $template, ?PointClinicalPathway $point = null): array
    {
        return $request->validate([
            'category_id' => 'required|integer|exists:clinical_pathway_categories,id',
            // parent_id (untuk sub-poin) harus poin lain di template yang sama.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('clinical_pathway_points', 'id')->where('template_id', $template->id),
            ],
            'label' => 'required|string|max:255',
            'filled_by' => ['required', Rule::in(PointClinicalPathway::FILLED_BY)],
            'required_days' => 'nullable|array',
            'required_days.*' => 'integer|min:1|max:'.$template->max_days,
        ]);
    }
}
