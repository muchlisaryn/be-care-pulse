<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master tarif iuran & layanan jenazah.
 *
 * Tabel & kolomnya berbahasa Inggris (`rates`), sedangkan kontrak API tetap
 * memakai `kode`, `nama`, `harga`, dst — penerjemahannya di model Rate.
 * URL tetap memakai kode tarif, bukan id.
 */
class TarifController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = Rate::query();

        if ($kategori = $request->query('kategori')) {
            $query->where('category', $kategori);
        }

        // Halaman Transaksi hanya butuh tarif berulang; halaman master tetap
        // memanggil tanpa filter ini dan menerima keduanya.
        if ($feeType = $request->query('fee_type')) {
            $query->where('fee_type', $feeType);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('rate_code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return response()->json($query->orderBy('code')->get());
        }

        return response()->json($query->orderBy('code')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:50', Rule::unique('rates', 'code')->whereNull('deleted_by')],
            'kategori' => ['nullable', 'string', 'max:50'],
            // Kolom baru, jadi tidak punya padanan nama lama — dikirim & dibaca
            // apa adanya sebagai `fee_type`.
            'fee_type' => ['nullable', Rule::in(Rate::FEE_TYPES)],
            'grup_tarif' => ['nullable', 'string', 'max:50'],
            'nama_grup' => ['nullable', 'string', 'max:255'],
            'nama' => ['required', 'string', 'max:255'],
            'kode_tarif' => ['nullable', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
            'harga' => ['required', 'numeric', 'min:0'],
        ]);

        $rate = $this->createOrRestore(Rate::class, 'code', Rate::fromLegacy($data));

        return response()->json($rate, 201);
    }

    public function show(Rate $rate)
    {
        return response()->json($rate);
    }

    public function update(Request $request, Rate $rate)
    {
        $data = $request->validate([
            'fee_type' => ['nullable', Rule::in(Rate::FEE_TYPES)],
            'grup_tarif' => ['nullable', 'string', 'max:50'],
            'nama_grup' => ['nullable', 'string', 'max:255'],
            'nama' => ['required', 'string', 'max:255'],
            'kode_tarif' => ['nullable', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string'],
            'harga' => ['required', 'numeric', 'min:0'],
        ]);

        $rate->update(Rate::fromLegacy($data));

        return response()->json($rate);
    }

    public function destroy(Rate $rate)
    {
        $rate->delete();

        return response()->json(['message' => 'Tarif dihapus.']);
    }
}
