<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\PackageBmhp;
use App\Models\PackageBmhpItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Master paket BMHP — kumpulan BMHP satuan beserta jumlahnya. Paket dan isinya
 * disimpan sekaligus dalam satu permintaan; penghapusan selalu soft delete.
 */
class PackageBmhpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = PackageBmhp::with('items.bmhp')
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%"))
            )
            ->latest()
            ->paginate(20);

        return $this->success('Data paket BMHP berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $package = DB::transaction(function () use ($validated) {
                $package = PackageBmhp::create([
                    'code' => $validated['code'],
                    'name' => $validated['name'],
                ]);

                $this->syncItems($package, $validated['items']);

                return $package;
            });

            return $this->success('Paket BMHP berhasil ditambahkan.', $package->load('items.bmhp'), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(PackageBmhp $package_bmhp): JsonResponse
    {
        return $this->success('Detail paket BMHP berhasil diambil.', $package_bmhp->load('items.bmhp'));
    }

    public function update(Request $request, PackageBmhp $package_bmhp): JsonResponse
    {
        $validated = $this->validatePayload($request, $package_bmhp);

        try {
            DB::transaction(function () use ($validated, $package_bmhp) {
                $package_bmhp->update([
                    'code' => $validated['code'],
                    'name' => $validated['name'],
                ]);

                $this->syncItems($package_bmhp, $validated['items']);
            });

            return $this->success('Paket BMHP berhasil diperbarui.', $package_bmhp->load('items.bmhp'));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(PackageBmhp $package_bmhp): JsonResponse
    {
        try {
            DB::transaction(function () use ($package_bmhp) {
                // Isinya ikut ditandai terhapus, satu per satu agar jejak
                // penghapusnya (deleted_by / deleted_at) tercatat di tiap baris.
                $package_bmhp->items()->get()->each->delete();
                $package_bmhp->delete();
            });

            return $this->success('Paket BMHP berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Samakan isi paket dengan kiriman: BMHP yang sudah ada diperbarui jumlahnya,
     * yang baru ditambahkan, yang tidak lagi dikirim ditandai terhapus.
     *
     * @param  array<int, array{bmhp_id: int, qty: float|int|string}>  $items
     */
    private function syncItems(PackageBmhp $package, array $items): void
    {
        $existing = $package->items()->get()->keyBy('bmhp_id');
        $kept = [];

        foreach ($items as $item) {
            $bmhpId = (int) $item['bmhp_id'];
            $kept[] = $bmhpId;

            if ($row = $existing->get($bmhpId)) {
                $row->update(['qty' => $item['qty']]);

                continue;
            }

            PackageBmhpItem::create([
                'package_bmhp_id' => $package->id,
                'bmhp_id' => $bmhpId,
                'qty' => $item['qty'],
            ]);
        }

        $existing->reject(fn (PackageBmhpItem $row) => in_array((int) $row->bmhp_id, $kept, true))
            ->each->delete();
    }

    private function validatePayload(Request $request, ?PackageBmhp $package = null): array
    {
        return $request->validate([
            // Kode diketik manual. Uniknya dicek terhadap SEMUA baris, termasuk yang
            // sudah dihapus — indeks unik di database juga mencakup baris itu.
            'code' => ['required', 'string', 'max:50', Rule::unique('mst_package_bmhp', 'code')->ignore($package?->id)],
            'name' => 'required|string|max:255',
            // Paket tanpa isi tidak punya arti.
            'items' => 'required|array|min:1',
            // Satu BMHP hanya boleh muncul sekali dalam satu paket.
            'items.*.bmhp_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('mst_bmhp', 'id')->whereNull('deleted_by'),
            ],
            'items.*.qty' => 'required|numeric|gt:0',
        ], [
            'code.required' => 'Kode paket wajib diisi.',
            'code.unique' => 'Kode paket sudah dipakai.',
            'items.required' => 'Paket harus berisi minimal satu BMHP.',
            'items.min' => 'Paket harus berisi minimal satu BMHP.',
            'items.*.bmhp_id.distinct' => 'BMHP yang sama tidak boleh dimasukkan dua kali.',
            'items.*.bmhp_id.exists' => 'BMHP tidak ditemukan di master BMHP.',
            'items.*.qty.gt' => 'Jumlah BMHP harus lebih dari 0.',
        ]);
    }
}
