<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Dipakai dua macam pemanggil: tabel master (paginasi normal) dan dropdown
        // pilihan ruangan di halaman order/distribusi yang butuh daftar lengkap.
        // Batas atas 500 supaya tetap aman kalau nilainya dikirim sembarangan.
        $perPage = min((int) $request->input('per_page', 20) ?: 20, 500);

        $data = Room::when(
            $request->search,
            fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
        )->paginate($perPage);

        return $this->success('Data ruangan berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:rooms,name',
            'layanan' => 'nullable|in:igd,rawat_jalan,rawat_inap',
        ]);

        try {
            $room = Room::create($validated);

            return $this->success('Ruangan berhasil ditambahkan.', $room, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Room $room): JsonResponse
    {
        return $this->success('Detail ruangan berhasil diambil.', $room);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:rooms,name,'.$room->id,
            'layanan' => 'nullable|in:igd,rawat_jalan,rawat_inap',
        ]);

        try {
            $room->update($validated);

            return $this->success('Ruangan berhasil diperbarui.', $room);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Room $room): JsonResponse
    {
        try {
            $room->delete();

            return $this->success('Ruangan berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
