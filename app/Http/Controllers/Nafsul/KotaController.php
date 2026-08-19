<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master kota.
 *
 * Tabel & kolomnya berbahasa Inggris (`cities`), sedangkan kontrak API tetap
 * memakai `kode` & `nama` — penerjemahannya ditangani model City.
 * URL tetap memakai kode (City::getRouteKeyName), bukan id.
 */
class KotaController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = City::query();

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
            'kode' => ['required', 'string', 'max:50', Rule::unique('cities', 'code')->whereNull('deleted_by')],
            'nama' => ['required', 'string', 'max:255'],
        ]);

        $city = $this->createOrRestore(City::class, 'code', City::fromLegacy($data));

        return response()->json($city, 201);
    }

    public function show(City $city)
    {
        return response()->json($city);
    }

    public function update(Request $request, City $city)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
        ]);

        $city->update(City::fromLegacy($data));

        return response()->json($city);
    }

    public function destroy(City $city)
    {
        $city->delete();

        return response()->json(['message' => 'Kota dihapus.']);
    }
}
