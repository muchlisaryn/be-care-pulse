<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\TitleMenus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->success('Registrasi berhasil.', [
                'user' => $user,
                'token' => $token,
            ], 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::where('username', $validated['username'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->error('Email/username atau password salah.', 401);
        }

        if ($user->deleted_by !== null) {
            return $this->error('Akun Anda telah dinonaktifkan.', 403);
        }

        $tokenName = $validated['device_name'] ?? 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->success('Login berhasil.', [
            'username' => $user->username,
            'token' => $token,
            'menus' => $this->buildMenuResponse($user),
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device_name' => $token->name,
                'last_used' => $token->last_used_at?->toDateTimeString(),
                'created_at' => $token->created_at->toDateTimeString(),
                'is_current' => $token->id === $currentId,
            ]);

        return $this->success('Berhasil mengambil daftar sesi aktif.', $sessions);
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->find($id);

        if (! $token) {
            return $this->error('Sesi tidak ditemukan.', 404);
        }

        try {
            $token->delete();

            return $this->success('Sesi berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        try {
            $request->user()->tokens()->delete();

            return $this->success('Semua sesi berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->success('Logout berhasil.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success('Data user berhasil diambil.', [
            'username' => $user->username,
            'name' => $user->name,
            'menus' => $this->buildMenuResponse($user),
        ]);
    }

    private function buildMenuResponse(User $user): array
    {
        $user->load(['authority.menus' => fn ($q) => $q->orderBy('sort_order')]);

        $allMenus = $user->authority?->menus ?? collect();
        $parentMenus = $allMenus->whereNull('parent_id');
        $childMenus = $allMenus->whereNotNull('parent_id');

        // Anak yang diberi akses tapi induknya tidak ikut diceklis — lazim terjadi
        // setelah sebuah menu dipindah ke induk baru. Anak semacam ini tidak punya
        // tempat menempel sehingga hilang dari sidebar walau otoritasnya sudah benar.
        // Induk pelengkap ditarik sebagai WADAH saja: `url`-nya dikosongkan supaya
        // halaman milik induk tidak ikut terbuka aksesnya.
        $orphanParentIds = $childMenus->pluck('parent_id')
            ->filter()
            ->unique()
            ->diff($parentMenus->pluck('id'))
            ->values();

        if ($orphanParentIds->isNotEmpty()) {
            $containers = Menu::whereIn('id', $orphanParentIds)
                ->whereNull('parent_id')
                ->get()
                ->each(fn (Menu $menu) => $menu->url = null);

            $parentMenus = $parentMenus->concat($containers);
        }

        // Induk tanpa judul dikumpulkan di kunci 0 agar tidak hilang saat difilter.
        $grouped = $parentMenus->sortBy('sort_order')
            ->groupBy(fn (Menu $menu) => (int) $menu->title_menu_id);

        $titleMenus = TitleMenus::whereIn('id', $grouped->keys()->filter()->all())
            ->orderBy('sort_order')
            ->get();

        $sections = $titleMenus->map(fn ($title) => [
            'title_menu' => $title->title,
            'menus' => $this->mapParentMenus($grouped->get($title->id, collect()), $childMenus),
        ]);

        // Induk tanpa grup (title_menu_id kosong atau menunjuk grup yang sudah
        // dihapus) tetap ditampilkan tanpa judul. Sebelumnya diam-diam dibuang,
        // sehingga menunya ikut hilang walau otoritasnya sudah diceklis — padahal
        // di halaman Master Menu menu tersebut tetap terlihat.
        $ungrouped = $grouped
            ->reject(fn ($menus, $id) => $titleMenus->contains('id', (int) $id))
            ->flatten(1);

        if ($ungrouped->isNotEmpty()) {
            $sections->push([
                'title_menu' => null,
                'menus' => $this->mapParentMenus($ungrouped->sortBy('sort_order'), $childMenus),
            ]);
        }

        return $sections->values()->toArray();
    }

    /**
     * Susun daftar menu induk beserta anak-anaknya sesuai bentuk response sidebar.
     *
     * @param  Collection<int, Menu>  $parents
     * @param  Collection<int, Menu>  $childMenus
     */
    private function mapParentMenus($parents, $childMenus): array
    {
        return $parents->map(function (Menu $menu) use ($childMenus) {
            $children = $childMenus->where('parent_id', $menu->id)->sortBy('sort_order');

            return [
                'name' => $menu->name,
                'url' => $menu->url,
                'icon' => $menu->icon,
                'sort_order' => $menu->sort_order,
                'is_open' => (bool) $menu->is_open,
                'open_sidebar' => (bool) $menu->open_sidebar,
                'menu' => $children->map(fn (Menu $child) => [
                    'name' => $child->name,
                    'url' => $child->url,
                    'icon' => $child->icon,
                    'open_sidebar' => (bool) $child->open_sidebar,
                ])->values()->toArray(),
            ];
        })->values()->toArray();
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,'.$user->id,
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'password_confirmation' => 'required_with:password|string',
        ]);

        try {
            $user->update([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                ...(! empty($validated['password']) ? ['password' => Hash::make($validated['password'])] : []),
            ]);

            $user->refresh();

            return $this->success('Data berhasil diperbarui.', $user);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,'.$user->id,
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
        ]);

        try {
            $user->update($validated);

            return $this->success('Profil berhasil diperbarui.', $user);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return $this->error('Password saat ini tidak sesuai.', 422);
        }

        try {
            $user->update(['password' => Hash::make($request->password)]);

            // Cabut sesi di perangkat LAIN, tapi pertahankan sesi yang sedang
            // dipakai agar user tidak ikut ter-logout di perangkat ini.
            $currentTokenId = $request->user()->currentAccessToken()->id;
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();

            return $this->success('Password berhasil diubah. Sesi di perangkat lain telah dikeluarkan.', [
                'token' => $request->bearerToken(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
