<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master wilayah.
 *
 * Tabel & kolomnya berbahasa Inggris (`regions`), sedangkan kontrak API tetap
 * memakai `kode` & `nama` — penerjemahannya ditangani model Region.
 * URL tetap memakai kode (Region::getRouteKeyName), bukan id.
 */
class WilayahController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = Region::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return response()->json($query->orderBy('name')->get());
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:50', Rule::unique('regions', 'code')->whereNull('deleted_by')],
            'nama' => ['required', 'string', 'max:255'],
        ]);

        $region = $this->createOrRestore(Region::class, 'code', Region::fromLegacy($data));

        return response()->json($region, 201);
    }

    public function show(Region $region)
    {
        return response()->json($region);
    }

    public function update(Request $request, Region $region)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
        ]);

        $region->update(Region::fromLegacy($data));

        return response()->json($region);
    }

    public function destroy(Region $region)
    {
        $region->delete();

        return response()->json(['message' => 'Wilayah dihapus.']);
    }
}
