<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master printer (Pengaturan → Master Printer). CRUD konfigurasi printer
 * struk/label: bahasa printer, koneksi, ukuran kertas/label, dll.
 */
class PrinterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Printer::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('ip_address', 'like', "%{$s}%")
                ->orWhere('device_path', 'like', "%{$s}%"))
        )
            ->latest()
            ->paginate(20);

        return $this->success('Data printer berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $printer = Printer::create($validated);

            return $this->success('Printer berhasil ditambahkan.', $printer, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Printer $printer): JsonResponse
    {
        return $this->success('Detail printer berhasil diambil.', $printer);
    }

    public function update(Request $request, Printer $printer): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $printer->update($validated);

            return $this->success('Printer berhasil diperbarui.', $printer);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Printer $printer): JsonResponse
    {
        try {
            $printer->delete();

            return $this->success('Printer berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'document_type' => ['required', Rule::in(['struk', 'label'])],
            'printer_language' => ['required', Rule::in(['escpos', 'tspl', 'zpl', 'epl'])],
            'connection_type' => ['required', Rule::in(['network', 'usb', 'bluetooth', 'serial'])],
            'ip_address' => 'nullable|string|max:255',
            'port' => 'nullable|integer',
            'device_path' => 'nullable|string|max:255',
            // receipt (struk) only
            'paper_size' => ['nullable', Rule::in(['58mm', '80mm'])],
            'char_per_line' => 'nullable|integer',
            'auto_cut' => 'boolean',
            // label only
            'label_width_mm' => 'nullable|integer',
            'label_height_mm' => 'nullable|integer',
            'label_gap_mm' => 'nullable|numeric',
            'code_page' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);
    }
}
