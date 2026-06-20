<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = User::with(['authority'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('username', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%"))
            ->paginate(20);

        return $this->success('Berhasil mengambil data user.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'no_telephone' => 'nullable|string|max:20',
            'authority_id' => 'required|integer|exists:authorities,id',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        try {
            $validated['password'] = Hash::make($validated['password']);
            $user = User::create($validated);
            $user->load(['authority']);

            return $this->success('User berhasil dibuat.', $user, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['authority']);

        return $this->success('Berhasil mengambil detail user.', $user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'username' => 'sometimes|required|string|max:100|unique:users,username,'.$user->id,
            'email' => 'sometimes|required|email|unique:users,email,'.$user->id,
            'no_telephone' => 'nullable|string|max:20',
            'authority_id' => 'nullable|integer|exists:authorities,id',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        try {
            if (! empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $user->update($validated);
            $user->load(['authority']);

            return $this->success('User berhasil diperbarui.', $user);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(User $user): JsonResponse
    {
        try {
            $user->delete();

            return $this->success('User berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
