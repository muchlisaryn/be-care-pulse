<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Occupation;
use App\Traits\ImportsExcelRows;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master pekerjaan.
 *
 * Tabel & kolomnya berbahasa Inggris (`occupations.name`), sedangkan kontrak API
 * tetap memakai `nama` — penerjemahannya ditangani model Occupation.
 */
class PekerjaanController extends Controller
{
    use ImportsExcelRows, RecreatesSoftDeleted;

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
        ], [
            'nama.required' => 'Nama Pekerjaan wajib diisi.',
            // Collation kolomnya `utf8mb4_unicode_ci`, jadi pemeriksaan ini
            // sudah mengabaikan beda huruf besar-kecil.
            'nama.unique' => 'Pekerjaan ":input" sudah ada.',
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
        ], [
            'nama.required' => 'Nama Pekerjaan wajib diisi.',
            'nama.unique' => 'Pekerjaan ":input" sudah ada.',
        ]);

        $occupation->update(Occupation::fromLegacy($data));

        return response()->json($occupation);
    }

    public function import(Request $request)
    {
        return $this->prosesImport($request, function (array $row) {
            $baris = $this->importRow($row);

            return ['id' => $baris->id, 'nama' => $baris->name];
        });
    }

    /**
     * Satu baris file Excel → satu Pekerjaan.
     *
     * Nama dibandingkan tanpa membedakan huruf besar-kecil, jadi "Guru"
     * dan "guru" terhitung sama. Perbandingannya ditulis eksplisit dengan
     * LOWER() dan tidak menyandarkan diri pada collation kolom: collation
     * `utf8mb4_unicode_ci` yang dipakai sekarang memang sudah mengabaikan
     * huruf besar-kecil, tapi aturan itu jadi tidak terlihat di kode dan akan
     * berubah diam-diam bila collation-nya suatu saat diganti.
     */
    private function importRow(array $row): Occupation
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
            ['nama' => 'Nama Pekerjaan']
        );
        $validator->stopOnFirstFailure();

        $data = $validator->validate();

        // Berbeda dengan `store` yang memulihkan baris terhapus: impor massal
        // tidak boleh diam-diam menimpa data yang sudah ada. `withTrashed()`
        // dipakai agar cakupannya sama dengan index unik di database — kalau
        // tidak, baris ini lolos validasi lalu gagal dengan galat SQL mentah.
        $bentrok = Occupation::withTrashed()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['nama'])])
            ->value('name');

        if ($bentrok !== null) {
            throw ValidationException::withMessages([
                'nama' => "Pekerjaan \"{$data['nama']}\" sudah ada (tercatat sebagai \"{$bentrok}\").",
            ]);
        }

        return Occupation::create(Occupation::fromLegacy($data));
    }

    public function destroy(Occupation $occupation)
    {
        $occupation->delete();

        return response()->json(['message' => 'Pekerjaan dihapus.']);
    }
}
