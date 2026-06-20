<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\TitleMenus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TitleMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = TitleMenus::with('menus')
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('sort_order')
            ->paginate(20);

        return $this->success('Berhasil mengambil data title menu.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $titleMenu = TitleMenus::create($validated);

            return $this->success('Title menu berhasil dibuat.', $titleMenu, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(TitleMenus $titleMenu): JsonResponse
    {
        $titleMenu->load('menus');

        return $this->success('Berhasil mengambil detail title menu.', $titleMenu);
    }

    public function update(Request $request, TitleMenus $titleMenu): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $titleMenu->update($validated);
            $titleMenu->load('menus');

            return $this->success('Title menu berhasil diperbarui.', $titleMenu);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(TitleMenus $titleMenu): JsonResponse
    {
        try {
            $titleMenu->delete();

            return $this->success('Title menu berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
