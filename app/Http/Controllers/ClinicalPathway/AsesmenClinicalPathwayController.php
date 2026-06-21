<?php

namespace App\Http\Controllers\ClinicalPathway;

use App\Http\Controllers\Controller;
use App\Models\AsesmenClinicalPathway;
use App\Models\AsesmenPointClinicalPathway;
use App\Models\CategoriClinicalPathway;
use App\Models\PointClinicalPathway;
use App\Models\VarianClinicalPathway;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class AsesmenClinicalPathwayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = AsesmenClinicalPathway::with(['template.icd10', 'ruang'])
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('nama_pasien', 'like', "%{$s}%")
                    ->orWhere('no_rm', 'like', "%{$s}%")
                    ->orWhere('diagnosa_masuk', 'like', "%{$s}%"))
            )
            ->when($request->ruang_id, fn ($q, $r) => $q->where('ruang_id', $r))
            ->when($request->status, function ($q, $s) {
                // selesai = pelaksana sudah verifikasi; belum = sebaliknya.
                if ($s === 'selesai') {
                    $q->whereNotNull('verifikasi_pelaksana_at');
                } elseif ($s === 'belum') {
                    $q->whereNull('verifikasi_pelaksana_at');
                }
            })
            ->latest()
            ->paginate(20);

        return $this->success('Data asesmen clinical pathway berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateAsesmen($request);

        try {
            $asesmen = AsesmenClinicalPathway::create($validated);

            return $this->success('Asesmen berhasil dibuat.', $asesmen->load(['template.icd10', 'ruang']), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Detail asesmen: data pasien + template + seluruh nilai ceklis poin. */
    public function show(AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $asesmen->load(['template.icd10', 'ruang', 'points']);

        return $this->success('Detail asesmen berhasil diambil.', $asesmen);
    }

    public function update(Request $request, AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $validated = $this->validateAsesmen($request);

        try {
            $asesmen->update($validated);

            return $this->success('Asesmen berhasil diperbarui.', $asesmen->load(['template.icd10', 'ruang']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(AsesmenClinicalPathway $asesmen): JsonResponse
    {
        try {
            $asesmen->delete();

            return $this->success('Asesmen berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Simpan (upsert) nilai ceklis satu poin pada asesmen — dipakai untuk
     * auto-save saat user menceklis hari atau mengetik keterangan.
     */
    public function savePoint(Request $request, AsesmenClinicalPathway $asesmen, PointClinicalPathway $point): JsonResponse
    {
        // Poin harus milik template yang sama dengan asesmen.
        if ($point->template_id !== $asesmen->template_id) {
            return $this->error('Poin tidak termasuk dalam formulir asesmen ini.', 422);
        }

        $maksimal = $asesmen->template->maksimal_hari;

        $validated = $request->validate([
            'checked_hari' => 'nullable|array',
            'checked_hari.*' => "integer|min:1|max:{$maksimal}",
            'keterangan' => 'nullable|string',
        ]);

        try {
            $value = AsesmenPointClinicalPathway::updateOrCreate(
                ['asesmen_id' => $asesmen->id, 'point_id' => $point->id],
                [
                    'checked_hari' => array_values(array_unique($validated['checked_hari'] ?? [])),
                    'keterangan' => $validated['keterangan'] ?? null,
                ],
            );

            return $this->success('Pengisian poin tersimpan.', $value);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Verifikasi / batal verifikasi clinical pathway untuk satu peran:
     * dokter (PJ), perawat (PJ), atau pelaksana (tanda CP selesai).
     *
     * Aturan:
     * - Pelaksana hanya bisa diverifikasi bila dokter & perawat sudah verifikasi.
     * - Verifikasi dokter/perawat tidak bisa dibatalkan selama pelaksana masih
     *   terverifikasi (batalkan pelaksana dulu) agar status tetap konsisten.
     */
    public function verify(Request $request, AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['dokter', 'perawat', 'pelaksana'])],
            'action' => ['required', Rule::in(['verify', 'batal'])],
        ]);

        $role = $validated['role'];
        $verify = $validated['action'] === 'verify';

        if ($verify && $role === 'pelaksana'
            && (! $asesmen->verifikasi_dokter_at || ! $asesmen->verifikasi_perawat_at)) {
            return $this->error('Verifikasi dokter & perawat penanggung jawab harus selesai dulu.', 422);
        }

        if (! $verify && in_array($role, ['dokter', 'perawat'], true) && $asesmen->verifikasi_pelaksana_at) {
            return $this->error('Batalkan verifikasi pelaksana terlebih dahulu.', 422);
        }

        try {
            $asesmen->update([
                "verifikasi_{$role}_by" => $verify ? $request->user()->username : null,
                "verifikasi_{$role}_at" => $verify ? now() : null,
            ]);

            $pesan = $verify ? 'Verifikasi berhasil disimpan.' : 'Verifikasi berhasil dibatalkan.';

            return $this->success($pesan, $asesmen->load(['template.icd10', 'ruang']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Cetak asesmen clinical pathway ke PDF (data pasien + ceklis poin per hari +
     * varian + verifikasi). Dikembalikan inline; frontend menampilkan preview &
     * menyediakan tombol download.
     */
    public function pdf(AsesmenClinicalPathway $asesmen): Response
    {
        $asesmen->load(['template.icd10', 'ruang', 'points']);
        $template = $asesmen->template;
        $maxHari = (int) ($template?->maksimal_hari ?? 0);
        $days = $maxHari > 0 ? range(1, $maxHari) : [];

        $categories = CategoriClinicalPathway::orderBy('urutan')->get();
        $points = PointClinicalPathway::where('template_id', $template?->id)
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();

        // Nilai ceklis per poin (point_id => {checked_hari, keterangan}).
        $values = $asesmen->points->keyBy('point_id');

        $childrenOf = fn ($parentId) => $points->where('parent_id', $parentId)->values();

        // Susun baris berpenomoran per kategori (mirip tampilan pengisian).
        $sections = [];
        foreach ($categories as $cat) {
            $tops = $points->where('categori_id', $cat->id)->whereNull('parent_id')->values();
            if ($tops->isEmpty()) {
                continue;
            }

            $rows = [];
            $walk = function ($point, $number, $depth) use (&$walk, &$rows, $childrenOf, $values) {
                $children = $childrenOf($point->id);
                $val = $values->get($point->id);
                $rows[] = [
                    'number' => $number,
                    'label' => $point->label,
                    'pengisi' => $point->pengisi,
                    'depth' => $depth,
                    'hasChildren' => $children->isNotEmpty(),
                    'checked' => $val?->checked_hari ?? [],
                    'keterangan' => $val?->keterangan,
                ];
                foreach ($children as $i => $child) {
                    $walk($child, $number.'.'.($i + 1), $depth + 1);
                }
            };
            foreach ($tops as $i => $top) {
                $walk($top, $cat->urutan.'.'.($i + 1), 0);
            }

            $sections[] = ['label' => $cat->label, 'urutan' => $cat->urutan, 'rows' => $rows];
        }

        $varians = VarianClinicalPathway::where('asesmen_id', $asesmen->id)
            ->orderBy('tanggal_waktu')
            ->get();

        // QR (barcode) verifikasi: berisi teks "Sudah diverifikasi oleh {username}".
        // Di-embed sebagai data URI SVG agar bisa dirender dompdf.
        $verifQr = function (?string $by): ?string {
            if (! $by) {
                return null;
            }
            $svg = QrCode::format('svg')->size(90)->margin(0)->generate("Sudah diverifikasi oleh {$by}");

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        };

        $verifs = [
            [
                'title' => 'Dokter Penanggung Jawab',
                'by' => $asesmen->verifikasi_dokter_by,
                'at' => $asesmen->verifikasi_dokter_at,
                'qr' => $verifQr($asesmen->verifikasi_dokter_by),
            ],
            [
                'title' => 'Perawat Penanggung Jawab',
                'by' => $asesmen->verifikasi_perawat_by,
                'at' => $asesmen->verifikasi_perawat_at,
                'qr' => $verifQr($asesmen->verifikasi_perawat_by),
            ],
            [
                'title' => 'Pelaksana Verifikasi',
                'by' => $asesmen->verifikasi_pelaksana_by,
                'at' => $asesmen->verifikasi_pelaksana_at,
                'qr' => $verifQr($asesmen->verifikasi_pelaksana_by),
            ],
        ];

        $pdf = Pdf::loadView('pdf.asesmen_clinical_pathway', [
            'asesmen' => $asesmen,
            'template' => $template,
            'maxHari' => $maxHari,
            'days' => $days,
            'sections' => $sections,
            'varians' => $varians,
            'verifs' => $verifs,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('asesmen-clinical-pathway-'.$asesmen->id.'.pdf');
    }

    private function validateAsesmen(Request $request): array
    {
        return $request->validate([
            // Wajib: template_id (formulir), no_rm, nama_pasien, ruang_id. Sisanya opsional.
            'template_id' => 'required|integer|exists:template_clinical_pathway,id',
            'no_rm' => 'required|string|max:255',
            'nama_pasien' => 'required|string|max:255',
            'jenis_kelamin' => ['nullable', Rule::in(AsesmenClinicalPathway::JENIS_KELAMIN)],
            'tanggal_lahir' => 'nullable|date',
            'diagnosa_masuk' => 'nullable|string|max:255',
            'penyakit_utama' => 'nullable|string|max:255',
            'penyakit_penyerta' => 'nullable|string|max:255',
            'komplikasi' => 'nullable|string|max:255',
            'tindakan' => 'nullable|string|max:255',
            'bb' => 'nullable|numeric|min:0',
            'tb' => 'nullable|numeric|min:0',
            'tanggal_jam_masuk' => 'nullable|date',
            'tanggal_jam_keluar' => 'nullable|date|after_or_equal:tanggal_jam_masuk',
            'lama_rawat' => 'nullable|integer|min:0',
            'rencana_rawat' => 'nullable|string|max:255',
            'ruang_id' => 'required|integer|exists:rooms,id',
            'kelas' => 'nullable|string|max:255',
            'rujukan' => 'sometimes|boolean',
        ]);
    }
}
