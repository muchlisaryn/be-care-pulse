<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Authority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Authority::when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->paginate(20);

        return $this->success('Berhasil mengambil data otoritas.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:authorities,name',
            'description' => 'nullable|string|max:255',
            'menu_ids' => 'nullable|array',
            'menu_ids.*' => 'integer|exists:menus,id',
        ]);

        try {
            $authority = Authority::create($validated);

            if (! empty($validated['menu_ids'])) {
                $authority->menus()->sync($validated['menu_ids']);
            }

            $authority->load('menus');

            return $this->success('Otoritas berhasil dibuat.', $authority, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Authority $authority): JsonResponse
    {
        $authority->load('menus');

        return $this->success('Berhasil mengambil detail otoritas.', $authority);
    }

    public function update(Request $request, Authority $authority): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100|unique:authorities,name,'.$authority->id,
            'description' => 'nullable|string|max:255',
            'menu_ids' => 'nullable|array',
            'menu_ids.*' => 'integer|exists:menus,id',
        ]);

        try {
            $authority->update($validated);
            $authority->menus()->sync($validated['menu_ids'] ?? []);
            $authority->load('menus');

            return $this->success('Otoritas berhasil diperbarui.', $authority);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Authority $authority): JsonResponse
    {
        try {
            $authority->delete();

            return $this->success('Otoritas berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
