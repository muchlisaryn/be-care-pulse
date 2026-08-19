<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\MaritalStatus;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master status nikah.
 *
 * Tabel & kolomnya berbahasa Inggris (`marital_statuses.name`), sedangkan
 * kontrak API tetap memakai `nama` — penerjemahannya di model MaritalStatus.
 */
class StatusNikahController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = MaritalStatus::query();

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
            'nama' => ['required', 'string', 'max:255', Rule::unique('marital_statuses', 'name')->whereNull('deleted_by')],
        ]);

        $maritalStatus = $this->createOrRestore(MaritalStatus::class, 'name', MaritalStatus::fromLegacy($data));

        return response()->json($maritalStatus, 201);
    }

    public function show(MaritalStatus $maritalStatus)
    {
        return response()->json($maritalStatus);
    }

    public function update(Request $request, MaritalStatus $maritalStatus)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('marital_statuses', 'name')->ignore($maritalStatus->id)->whereNull('deleted_by')],
        ]);

        $maritalStatus->update(MaritalStatus::fromLegacy($data));

        return response()->json($maritalStatus);
    }

    public function destroy(MaritalStatus $maritalStatus)
    {
        $maritalStatus->delete();

        return response()->json(['message' => 'Status nikah dihapus.']);
    }
}
