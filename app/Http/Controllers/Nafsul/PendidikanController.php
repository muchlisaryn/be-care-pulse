<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Education;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master pendidikan.
 *
 * Tabel & kolomnya berbahasa Inggris (`educations.name`), sedangkan kontrak API
 * tetap memakai `nama` — penerjemahannya ditangani model Education.
 */
class PendidikanController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = Education::query();

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->boolean('all')) {
            return response()->json($query->orderBy('name')->get());
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('educations', 'name')->whereNull('deleted_by')],
        ]);

        $education = $this->createOrRestore(Education::class, 'name', Education::fromLegacy($data));

        return response()->json($education, 201);
    }

    public function show(Education $education)
    {
        return response()->json($education);
    }

    public function update(Request $request, Education $education)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('educations', 'name')->ignore($education->id)->whereNull('deleted_by')],
        ]);

        $education->update(Education::fromLegacy($data));

        return response()->json($education);
    }

    public function destroy(Education $education)
    {
        $education->delete();

        return response()->json(['message' => 'Pendidikan dihapus.']);
    }
}
