<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * Impor kota dari Excel, dikirim frontend per batch (maks 50 baris).
     *
     * Satu baris gagal tidak menggagalkan batch: tiap baris dilaporkan
     * status-nya sendiri supaya pengguna bisa memperbaiki lalu mengirim ulang
     * hanya baris yang bermasalah.
     */
    public function import(Request $request)
    {
        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*' => ['array'],
        ]);

        $hasil = [];
        $berhasil = 0;

        foreach ($payload['rows'] as $i => $row) {
            // `baris` = nomor baris di file Excel, dikirim FE supaya pesan
            // kesalahan menunjuk ke baris yang benar di file aslinya.
            $baris = (int) ($row['baris'] ?? $i + 1);
            $nama = trim((string) ($row['nama'] ?? ''));

            try {
                $city = $this->importRow($row);
                $berhasil++;
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'ok',
                    'kode' => $city->code,
                    'nama' => $city->name,
                ];
            } catch (ValidationException $e) {
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'gagal',
                    'nama' => $nama,
                    'pesan' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'gagal',
                    'nama' => $nama,
                    'pesan' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'berhasil' => $berhasil,
            'gagal' => count($hasil) - $berhasil,
            'hasil' => $hasil,
        ]);
    }

    /**
     * Satu baris file Excel → satu kota.
     *
     * Berbeda dengan ketua kelompok yang kodenya bisa dibuat otomatis, kode kota
     * WAJIB diisi di file: kode itu yang dipakai form anggota (`kode_kota_lahir`)
     * dan biasanya mengikuti kode wilayah yang sudah baku.
     */
    private function importRow(array $row): City
    {
        $data = [];
        foreach (['kode', 'nama'] as $field) {
            $value = $row[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

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
                'nama' => 'Nama Kota',
            ]
        );
        $validator->stopOnFirstFailure();

        $data = $validator->validate();

        // Berbeda dengan `store` yang memulihkan baris terhapus: impor massal
        // tidak boleh diam-diam menimpa data yang sudah ada.
        if (City::withTrashed()->where('code', $data['kode'])->exists()) {
            throw ValidationException::withMessages([
                'kode' => "Kode {$data['kode']} sudah dipakai kota lain.",
            ]);
        }

        return City::create(City::fromLegacy($data));
    }
}
