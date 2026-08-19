<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Occupation;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master pekerjaan.
 *
 * Tabel & kolomnya berbahasa Inggris (`occupations.name`), sedangkan kontrak API
 * tetap memakai `nama` — penerjemahannya ditangani model Occupation.
 */
class PekerjaanController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = Occupation::query();

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
            'nama' => ['required', 'string', 'max:255', Rule::unique('occupations', 'name')->whereNull('deleted_by')],
        ]);

        $occupation = $this->createOrRestore(Occupation::class, 'name', Occupation::fromLegacy($data));

        return response()->json($occupation, 201);
    }

    public function show(Occupation $occupation)
    {
        return response()->json($occupation);
    }

    public function update(Request $request, Occupation $occupation)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('occupations', 'name')->ignore($occupation->id)->whereNull('deleted_by')],
        ]);

        $occupation->update(Occupation::fromLegacy($data));

        return response()->json($occupation);
    }

    public function destroy(Occupation $occupation)
    {
        $occupation->delete();

        return response()->json(['message' => 'Pekerjaan dihapus.']);
    }
}
