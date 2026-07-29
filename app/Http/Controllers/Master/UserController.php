<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Authority;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // `per_page` dipakai halaman Export agar seluruh user bisa ditarik dalam
        // sedikit request; dibatasi 200 supaya tidak bisa dipakai menarik semuanya
        // sekaligus. Tanpa parameter, tetap 20 seperti daftar biasa.
        $perPage = min(max((int) $request->input('per_page', 20), 1), 200);

        $data = User::with(['authority'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('username', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%"))
            ->paginate($perPage);

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

    /** Maksimum baris per panggilan import — klien wajib memecah berkasnya. */
    private const IMPORT_CHUNK_MAX = 200;

    /**
     * Import user PER BATCH. Berkas TIDAK diunggah utuh: klien mem-parse berkasnya,
     * lalu mengirim potongan baris berkali-kali (maks. IMPORT_CHUNK_MAX per panggilan)
     * sambil menampilkan progres. Dengan begitu 1000+ baris tidak pernah jadi satu
     * request panjang yang rawan timeout / kehabisan memori.
     *
     * Satu baris gagal TIDAK membatalkan baris lain: tiap baris divalidasi & disimpan
     * sendiri, yang gagal dikembalikan di `errors` lengkap dengan nomor barisnya di
     * berkas asal supaya bisa diperbaiki lalu diimport ulang.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:'.self::IMPORT_CHUNK_MAX,
            'rows.*.row' => 'nullable|integer',
            'rows.*.name' => 'nullable',
            'rows.*.username' => 'nullable',
            'rows.*.email' => 'nullable',
            'rows.*.no_telephone' => 'nullable',
            'rows.*.authority_id' => 'nullable',
            'rows.*.authority' => 'nullable',
            'rows.*.password' => 'nullable',
            // Password untuk baris yang tidak mencantumkannya sendiri. Diminta sekali
            // di modal import — sengaja TIDAK ada default tersembunyi di server.
            'default_password' => 'required|string|min:8',
        ]);

        // Otoritas dipetakan sekali per panggilan: berkas biasanya menulis NAMA
        // otoritas, bukan id. Pencocokan nama tidak peka huruf besar/kecil.
        $authorities = Authority::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [$this->normalizeName($name) => (int) $id])
            ->all();
        $authorityIds = array_flip($authorities);

        $created = 0;
        $errors = [];

        foreach ($validated['rows'] as $i => $raw) {
            $rowNo = $raw['row'] ?? ($i + 1);
            $data = [
                'name' => $this->cleanCell($raw['name'] ?? null),
                'username' => $this->cleanCell($raw['username'] ?? null),
                'email' => $this->cleanCell($raw['email'] ?? null),
                'no_telephone' => $this->cleanCell($raw['no_telephone'] ?? null),
                'password' => $this->cleanCell($raw['password'] ?? null) ?? $validated['default_password'],
            ];

            // authority_id langsung menang; bila kosong, cari lewat nama otoritas.
            $authorityId = $this->cleanCell($raw['authority_id'] ?? null);
            if ($authorityId === null) {
                $name = $this->normalizeName($this->cleanCell($raw['authority'] ?? null));
                $authorityId = $authorities[$name] ?? null;
            }
            $data['authority_id'] = $authorityId === null ? null : (int) $authorityId;

            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->whereNull('deleted_by')],
                // Email OPSIONAL saat import: banyak petugas tidak punya email kantor
                // & berkasnya sering tidak memuat kolom itu. Bila diisi tetap harus
                // valid dan unik (kolomnya nullable, jadi NULL boleh berulang).
                'email' => ['nullable', 'email', Rule::unique('users', 'email')->whereNull('deleted_by')],
                'no_telephone' => 'nullable|string|max:20',
                'authority_id' => ['required', 'integer', Rule::in(array_keys($authorityIds))],
                'password' => 'required|string|min:8',
            ], [
                // Nama otoritas yang tak dikenal berakhir sebagai authority_id null —
                // pesan bawaan ("field is required") menyesatkan, jadi diganti agar
                // petugas tahu yang salah adalah isi kolom `authority`.
                'authority_id.required' => 'Kolom authority kosong atau nama otoritasnya tidak dikenal.',
                'authority_id.in' => 'Otoritas tidak ditemukan.',
                // Pesan bawaan berbahasa Inggris & tidak menyebut nilainya. Petugas
                // perlu tahu username mana yang bentrok agar bisa langsung diperbaiki
                // di berkas baris gagal yang diunduh dari modal import.
                'username.unique' => 'Username ":input" sudah dipakai user lain.',
                'username.required' => 'Kolom username wajib diisi.',
                'name.required' => 'Kolom name wajib diisi.',
                'email.unique' => 'Email ":input" sudah dipakai user lain.',
            ], [
                'authority_id' => 'otoritas',
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'row' => (int) $rowNo,
                    'username' => $data['username'],
                    'message' => implode(' ', $validator->errors()->all()),
                ];

                continue;
            }

            try {
                $data['password'] = Hash::make($data['password']);
                User::create($data);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => (int) $rowNo,
                    'username' => $data['username'],
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $this->success('Batch import selesai.', [
            'processed' => count($validated['rows']),
            'created' => $created,
            'failed' => count($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * Bentuk baku nama otoritas untuk dicocokkan: huruf besar/kecil diabaikan dan
     * spasi ganda dirapatkan. Petugas mengetik "administrator", "Administrator",
     * maupun "Perawat  CSSD" — ketiganya harus mengarah ke otoritas yang sama.
     * Harus setara dengan normalize() di halaman import frontend.
     */
    private function normalizeName($value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * Rapikan satu sel berkas: spreadsheet kerap mengirim angka, spasi berlebih, atau
     * sel kosong sebagai string kosong — semuanya diseragamkan jadi string atau null.
     */
    private function cleanCell($value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
