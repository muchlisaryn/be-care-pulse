<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Condition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConditionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Condition::when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->paginate(20);

        return $this->success('Data kondisi berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:conditions,name',
        ]);

        try {
            $condition = Condition::create($validated);

            return $this->success('Kondisi berhasil ditambahkan.', $condition, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Condition $condition): JsonResponse
    {
        return $this->success('Detail kondisi berhasil diambil.', $condition);
    }

    public function update(Request $request, Condition $condition): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:conditions,name,'.$condition->id,
        ]);

        try {
            $condition->update($validated);

            return $this->success('Kondisi berhasil diperbarui.', $condition);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Condition $condition): JsonResponse
    {
        try {
            $condition->delete();

            return $this->success('Kondisi berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
