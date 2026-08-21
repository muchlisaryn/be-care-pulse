<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Traits\ImportsExcelRows;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master wilayah.
 *
 * Tabel & kolomnya berbahasa Inggris (`regions`), sedangkan kontrak API tetap
 * memakai `kode` & `nama` — penerjemahannya ditangani model Region.
 * URL tetap memakai kode (Region::getRouteKeyName), bukan id.
 */
class WilayahController extends Controller
{
    use ImportsExcelRows, RecreatesSoftDeleted;

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
        ], [
            'kode.required' => 'Kode wajib diisi.',
            'kode.unique' => 'Kode ":input" sudah dipakai wilayah lain.',
            'nama.required' => 'Nama Wilayah wajib diisi.',
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

    public function import(Request $request)
    {
        return $this->prosesImport($request, function (array $row) {
            $region = $this->importRow($row);

            return ['kode' => $region->code, 'nama' => $region->name];
        });
    }

    /**
     * Satu baris file Excel → satu wilayah.
     *
     * `kode` wajib diisi di file: kode itulah yang dirujuk master kota dan form
     * anggota (`kode_wilayah`), dan biasanya sudah baku di data lama.
     */
    private function importRow(array $row): Region
    {
        $data = $this->ambilKolom($row, ['kode', 'nama']);

        $validator = Validator::make(
            $data,
            [
                'kode' => ['required', 'string', 'max:50'],
                'nama' => ['required', 'string', 'max:255'],
            ],
            [
                'required' => ':attribute wajib diisi.',
                'max' => ':attribute terlalu panjang (maks :max karakter).',
                'string' => ':attribute harus berupa teks.',
            ],
            [
                'kode' => 'Kode',
                'nama' => 'Nama Wilayah',
            ]
        );
        $validator->stopOnFirstFailure();

        $data = $validator->validate();

        // Berbeda dengan `store` yang memulihkan baris terhapus: impor massal
        // tidak boleh diam-diam menimpa data yang sudah ada. `withTrashed()`
        // dipakai agar cakupannya sama dengan index unik di database — kalau
        // tidak, baris ini lolos validasi lalu gagal dengan galat SQL mentah.
        if (Region::withTrashed()->where('code', $data['kode'])->exists()) {
            throw ValidationException::withMessages([
                'kode' => "Kode {$data['kode']} sudah dipakai wilayah lain.",
            ]);
        }

        return Region::create(Region::fromLegacy($data));
    }

    public function destroy(Region $region)
    {
        $region->delete();

        return response()->json(['message' => 'Wilayah dihapus.']);
    }
}
