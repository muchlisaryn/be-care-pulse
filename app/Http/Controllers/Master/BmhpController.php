<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Bmhp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'unit' => 'sometimes|string|max:50',
            'stock_qty' => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
        ]);

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
            'name' => 'sometimes|required|string|max:255',
            'unit' => 'sometimes|string|max:50',
            'stock_qty' => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
        ]);

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
}
