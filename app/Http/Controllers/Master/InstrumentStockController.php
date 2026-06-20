<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class InstrumentStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = InstrumentStock::with(['instrument', 'condition'])
            ->when($request->instrument_id, fn ($q, $id) => $q->where('instrument_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('instrument', fn ($q) => $q->where('name', 'like', "%{$s}%"))
            )
            ->paginate(20);

        return $this->success('Data stok instrumen berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instrument_id' => 'required|integer|exists:instruments,id',
            'condition_id' => 'nullable|integer|exists:conditions,id',
            'status' => ['nullable', Rule::in(InstrumentStock::STATUSES)],
        ]);

        try {
            $stock = InstrumentStock::create($validated);
            $stock->load(['instrument', 'condition']);

            return $this->success('Stok instrumen berhasil ditambahkan.', $stock, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(InstrumentStock $instrumentStock): JsonResponse
    {
        $instrumentStock->load(['instrument', 'condition']);

        return $this->success('Detail stok instrumen berhasil diambil.', $instrumentStock);
    }

    public function update(Request $request, InstrumentStock $instrumentStock): JsonResponse
    {
        $validated = $request->validate([
            'instrument_id' => 'required|integer|exists:instruments,id',
            'condition_id' => 'nullable|integer|exists:conditions,id',
            'status' => ['nullable', Rule::in(InstrumentStock::STATUSES)],
        ]);

        try {
            $instrumentStock->update($validated);
            $instrumentStock->load(['instrument', 'condition']);

            return $this->success('Stok instrumen berhasil diperbarui.', $instrumentStock);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(InstrumentStock $instrumentStock): JsonResponse
    {
        try {
            $instrumentStock->delete();

            return $this->success('Stok instrumen berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Hasilkan QR Code (SVG) dari kode unit instrumen, untuk dicetak jadi label.
     * Dikembalikan sebagai data URI base64 agar mudah ditampilkan/dicetak di frontend.
     */
    public function qr(InstrumentStock $instrumentStock): JsonResponse
    {
        try {
            $svg = QrCode::format('svg')->size(300)->margin(1)->generate($instrumentStock->code);

            return $this->success('QR Code instrumen berhasil dibuat.', [
                'code' => $instrumentStock->code,
                'qr_svg' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Cari unit instrumen berdasarkan kode hasil scan QR Code.
     * Dipakai saat serah-terima (peminjaman/pengembalian) agar tidak perlu ketik manual.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $stock = InstrumentStock::with(['instrument', 'condition'])
            ->where('code', $validated['code'])
            ->first();

        if (! $stock) {
            return $this->error('Instrumen dengan kode tersebut tidak ditemukan.', 404);
        }

        return $this->success('Instrumen ditemukan.', $stock);
    }

    /**
     * Riwayat pergerakan/perubahan status sebuah unit instrumen (terbaru dulu).
     */
    public function logs(InstrumentStock $instrumentStock): JsonResponse
    {
        $logs = $instrumentStock->logs()->latest('id')->paginate(20);

        return $this->success('Riwayat pergerakan unit berhasil diambil.', $logs);
    }
}
