<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Icd10;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Icd10Controller extends Controller
{
    /** Ambil daftar ICD 10 (paginasi + pencarian kode/nama/versi). */
    public function index(Request $request): JsonResponse
    {
        $data = Icd10::when(
            $request->search,
            fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                ->orWhere('display', 'like', "%{$s}%")
                ->orWhere('version', 'like', "%{$s}%"))
        )
            ->orderBy('code')
            ->paginate(20);

        return $this->success('Data ICD 10 berhasil diambil.', $data);
    }

    /** Simpan satu data ICD 10 baru. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'display' => 'required|string|max:255',
            'version' => 'required|string|max:255',
        ]);

        try {
            $icd10 = Icd10::create($validated);

            return $this->success('ICD 10 berhasil ditambahkan.', $icd10, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Tampilkan detail satu ICD 10. */
    public function show(Icd10 $icd10): JsonResponse
    {
        return $this->success('Detail ICD 10 berhasil diambil.', $icd10);
    }

    /** Perbarui data ICD 10. */
    public function update(Request $request, Icd10 $icd10): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'display' => 'required|string|max:255',
            'version' => 'required|string|max:255',
        ]);

        try {
            $icd10->update($validated);

            return $this->success('ICD 10 berhasil diperbarui.', $icd10);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Hapus data ICD 10. */
    public function destroy(Icd10 $icd10): JsonResponse
    {
        try {
            $icd10->delete();

            return $this->success('ICD 10 berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Impor massal dari Excel (sudah di-parse frontend jadi baris {code, display,
     * version}). Baris yang kombinasi code + version-nya SUDAH ADA di database
     * akan di-skip. Mengembalikan ringkasan jumlah masuk / dilewati.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.code' => 'required|string|max:255',
            'items.*.display' => 'required|string|max:255',
            'items.*.version' => 'required|string|max:255',
        ]);

        try {
            $items = $validated['items'];

            // Cek duplikat hanya untuk code yang relevan (bukan seluruh tabel) agar
            // hemat memori — frontend mengirim per-batch, jadi tiap request ringan.
            $codes = array_values(array_unique(array_map(fn ($i) => $i['code'], $items)));
            $existing = Icd10::whereIn('code', $codes)
                ->get(['code', 'version'])
                ->map(fn ($r) => $r->code.'|'.$r->version)
                ->flip();

            $now = now();
            $by = auth()->user()?->name;
            $seen = [];          // cegah duplikat di dalam batch yang sama
            $rows = [];
            $skippedRows = [];   // detail baris yang dilewati + alasannya

            foreach ($items as $item) {
                $key = $item['code'].'|'.$item['version'];

                // Sudah ada di database (kombinasi code + version).
                if (isset($existing[$key])) {
                    $skippedRows[] = [
                        'code' => $item['code'],
                        'display' => $item['display'],
                        'version' => $item['version'],
                        'reason' => 'Code & version sudah ada di database',
                    ];

                    continue;
                }

                // Duplikat di dalam file yang diunggah.
                if (isset($seen[$key])) {
                    $skippedRows[] = [
                        'code' => $item['code'],
                        'display' => $item['display'],
                        'version' => $item['version'],
                        'reason' => 'Duplikat di dalam file (code & version sama)',
                    ];

                    continue;
                }

                $seen[$key] = true;
                $rows[] = [
                    'code' => $item['code'],
                    'display' => $item['display'],
                    'version' => $item['version'],
                    'created_by' => $by,
                    'updated_by' => $by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Bulk insert (di-chunk) — jauh lebih hemat & cepat daripada create() per baris.
            if (! empty($rows)) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    Icd10::insert($chunk);
                }
            }

            return $this->success('Impor ICD 10 selesai.', [
                'imported' => count($rows),
                'skipped' => count($skippedRows),
                'total' => count($items),
                'skipped_rows' => $skippedRows,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
