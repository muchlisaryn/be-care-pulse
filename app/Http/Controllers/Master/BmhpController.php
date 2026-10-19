<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Bmhp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BmhpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Bmhp::when(
            $request->search,
            fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
        )
            ->orderBy('name')
            ->paginate(20);

        return $this->success('Data BMHP berhasil diambil.', $data);
    }

    /**
     * Seluruh BMHP aktif tanpa paginasi — pilihan dropdown (mis. isi paket BMHP).
     * Satuan & harga ikut dikirim agar pemakainya bisa menampilkan dan menjumlah
     * tanpa permintaan kedua.
     */
    public function options(): JsonResponse
    {
        $data = Bmhp::orderBy('name')
            ->get()
            ->map(fn (Bmhp $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
                'unit' => $b->unit,
                'price' => $b->price,
            ]);

        return $this->success('Daftar BMHP berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Kode diketik manual. Uniknya dicek terhadap SEMUA baris, termasuk yang
            // sudah dihapus — indeks unik di database juga mencakup baris itu.
            'code' => ['required', 'string', 'max:50', Rule::unique('mst_bmhp', 'code')],
            'name' => 'required|string|max:255',
            // Satuan dipilih dari master satuan yang masih aktif.
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_by')],
            'stock_qty' => 'sometimes|numeric|min:0',
            'price' => 'sometimes|numeric|min:0',
        ], $this->messages());

        try {
            $bmhp = Bmhp::create($validated);

            return $this->success('BMHP berhasil ditambahkan.', $bmhp, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Bmhp $bmhp): JsonResponse
    {
        return $this->success('Detail BMHP berhasil diambil.', $bmhp);
    }

    public function update(Request $request, Bmhp $bmhp): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('mst_bmhp', 'code')->ignore($bmhp->id)],
            'name' => 'sometimes|required|string|max:255',
            'unit_id' => ['sometimes', 'required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_by')],
            'stock_qty' => 'sometimes|numeric|min:0',
            'price' => 'sometimes|numeric|min:0',
        ], $this->messages());

        try {
            $bmhp->update($validated);

            return $this->success('BMHP berhasil diperbarui.', $bmhp);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Bmhp $bmhp): JsonResponse
    {
        try {
            $bmhp->delete();

            return $this->success('BMHP berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function messages(): array
    {
        return [
            'code.required' => 'Kode BMHP wajib diisi.',
            'code.unique' => 'Kode BMHP sudah dipakai.',
            'unit_id.required' => 'Satuan wajib dipilih.',
            'unit_id.exists' => 'Satuan tidak ditemukan di master satuan.',
        ];
    }
}
