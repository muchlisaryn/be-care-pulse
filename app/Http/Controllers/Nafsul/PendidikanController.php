<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Education;
use App\Traits\ImportsExcelRows;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master pendidikan.
 *
 * Tabel & kolomnya berbahasa Inggris (`educations.name`), sedangkan kontrak API
 * tetap memakai `nama` — penerjemahannya ditangani model Education.
 */
class PendidikanController extends Controller
{
    use ImportsExcelRows, RecreatesSoftDeleted;

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
        ], [
            'nama.required' => 'Nama Pendidikan wajib diisi.',
            // Collation kolomnya `utf8mb4_unicode_ci`, jadi pemeriksaan ini
            // sudah mengabaikan beda huruf besar-kecil.
            'nama.unique' => 'Pendidikan ":input" sudah ada.',
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
        ], [
            'nama.required' => 'Nama Pendidikan wajib diisi.',
            'nama.unique' => 'Pendidikan ":input" sudah ada.',
        ]);

        $education->update(Education::fromLegacy($data));

        return response()->json($education);
    }

    public function import(Request $request)
    {
        return $this->prosesImport($request, function (array $row) {
            $baris = $this->importRow($row);

            return ['id' => $baris->id, 'nama' => $baris->name];
        });
    }

    /**
     * Satu baris file Excel → satu Pendidikan.
     *
     * Nama dibandingkan tanpa membedakan huruf besar-kecil, jadi "SMA"
     * dan "sma" terhitung sama. Perbandingannya ditulis eksplisit dengan
     * LOWER() dan tidak menyandarkan diri pada collation kolom: collation
     * `utf8mb4_unicode_ci` yang dipakai sekarang memang sudah mengabaikan
     * huruf besar-kecil, tapi aturan itu jadi tidak terlihat di kode dan akan
     * berubah diam-diam bila collation-nya suatu saat diganti.
     */
    private function importRow(array $row): Education
    {
        $data = $this->ambilKolom($row, ['nama']);

        $validator = Validator::make(
            $data,
            ['nama' => ['required', 'string', 'max:255']],
            [
                'required' => ':attribute wajib diisi.',
                'max' => ':attribute terlalu panjang (maks :max karakter).',
                'string' => ':attribute harus berupa teks.',
            ],
            ['nama' => 'Nama Pendidikan']
        );
        $validator->stopOnFirstFailure();

        $data = $validator->validate();

        // Berbeda dengan `store` yang memulihkan baris terhapus: impor massal
        // tidak boleh diam-diam menimpa data yang sudah ada. `withTrashed()`
        // dipakai agar cakupannya sama dengan index unik di database — kalau
        // tidak, baris ini lolos validasi lalu gagal dengan galat SQL mentah.
        $bentrok = Education::withTrashed()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['nama'])])
            ->value('name');

        if ($bentrok !== null) {
            throw ValidationException::withMessages([
                'nama' => "Pendidikan \"{$data['nama']}\" sudah ada (tercatat sebagai \"{$bentrok}\").",
            ]);
        }

        return Education::create(Education::fromLegacy($data));
    }

    public function destroy(Education $education)
    {
        $education->delete();

        return response()->json(['message' => 'Pendidikan dihapus.']);
    }
}
