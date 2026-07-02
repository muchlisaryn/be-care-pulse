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
    /** Peran verifikasi → prefix kolom verifikasi di database. */
    private const VERIFY_ROLE_COLUMN = [
        'dokter' => 'doctor',
        'perawat' => 'nurse',
        'pelaksana' => 'executor',
    ];

    /** Daftar asesmen (paginasi + filter pencarian, ruang, & status verifikasi). */
    public function index(Request $request): JsonResponse
    {
        $data = AsesmenClinicalPathway::with(['template.icd10', 'room'])
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('patient_name', 'like', "%{$s}%")
                    ->orWhere('medical_record_no', 'like', "%{$s}%")
                    ->orWhere('admission_diagnosis', 'like', "%{$s}%"))
            )
            ->when($request->room_id, fn ($q, $r) => $q->where('room_id', $r))
            ->when($request->status, function ($q, $s) {
                // selesai = pelaksana sudah verifikasi; belum = sebaliknya.
                if ($s === 'selesai') {
                    $q->whereNotNull('executor_verified_at');
                } elseif ($s === 'belum') {
                    $q->whereNull('executor_verified_at');
                }
            })
            ->latest()
            ->paginate(20);

        return $this->success('Data asesmen clinical pathway berhasil diambil.', $data);
    }

    /** Buat asesmen baru (data pasien + template). */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateAsesmen($request);

        try {
            $asesmen = AsesmenClinicalPathway::create($validated);

            return $this->success('Asesmen berhasil dibuat.', $asesmen->load(['template.icd10', 'room']), 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Detail asesmen: data pasien + template + seluruh nilai ceklis poin. */
    public function show(AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $asesmen->load(['template.icd10', 'room', 'points']);

        return $this->success('Detail asesmen berhasil diambil.', $asesmen);
    }

    /** Perbarui data asesmen. */
    public function update(Request $request, AsesmenClinicalPathway $asesmen): JsonResponse
    {
        $validated = $this->validateAsesmen($request);

        try {
            $asesmen->update($validated);

            return $this->success('Asesmen berhasil diperbarui.', $asesmen->load(['template.icd10', 'room']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Hapus asesmen. */
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
     * auto-save saat user menceklis hari atau mengetik catatan.
     */
    public function savePoint(Request $request, AsesmenClinicalPathway $asesmen, PointClinicalPathway $point): JsonResponse
    {
        // Poin harus milik template yang sama dengan asesmen.
        if ($point->template_id !== $asesmen->template_id) {
            return $this->error('Poin tidak termasuk dalam formulir asesmen ini.', 422);
        }

        $maxDays = $asesmen->template->max_days;

        $validated = $request->validate([
            'checked_days' => 'nullable|array',
            'checked_days.*' => "integer|min:1|max:{$maxDays}",
            'note' => 'nullable|string',
        ]);

        try {
            $value = AsesmenPointClinicalPathway::updateOrCreate(
                ['assessment_id' => $asesmen->id, 'point_id' => $point->id],
                [
                    'checked_days' => array_values(array_unique($validated['checked_days'] ?? [])),
                    'note' => $validated['note'] ?? null,
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
            'role' => ['required', Rule::in(array_keys(self::VERIFY_ROLE_COLUMN))],
            'action' => ['required', Rule::in(['verify', 'batal'])],
        ]);

        $role = $validated['role'];
        $column = self::VERIFY_ROLE_COLUMN[$role]; // doctor | nurse | executor
        $verify = $validated['action'] === 'verify';

        if ($verify && $role === 'pelaksana'
            && (! $asesmen->doctor_verified_at || ! $asesmen->nurse_verified_at)) {
            return $this->error('Verifikasi dokter & perawat penanggung jawab harus selesai dulu.', 422);
        }

        if (! $verify && in_array($role, ['dokter', 'perawat'], true) && $asesmen->executor_verified_at) {
            return $this->error('Batalkan verifikasi pelaksana terlebih dahulu.', 422);
        }

        try {
            $asesmen->update([
                "{$column}_verified_by" => $verify ? $request->user()->username : null,
                "{$column}_verified_at" => $verify ? now() : null,
            ]);

            $pesan = $verify ? 'Verifikasi berhasil disimpan.' : 'Verifikasi berhasil dibatalkan.';

            return $this->success($pesan, $asesmen->load(['template.icd10', 'room']));
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
        $asesmen->load(['template.icd10', 'room', 'points']);
        $template = $asesmen->template;
        $maxDays = (int) ($template?->max_days ?? 0);
        $days = $maxDays > 0 ? range(1, $maxDays) : [];

        $categories = CategoriClinicalPathway::orderBy('sort_order')->get();
        $points = PointClinicalPathway::where('template_id', $template?->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Nilai ceklis per poin (point_id => {checked_days, note}).
        $values = $asesmen->points->keyBy('point_id');

        $childrenOf = fn ($parentId) => $points->where('parent_id', $parentId)->values();

        // Susun baris berpenomoran per kategori (mirip tampilan pengisian).
        $sections = [];
        foreach ($categories as $cat) {
            $tops = $points->where('category_id', $cat->id)->whereNull('parent_id')->values();
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
                    'filled_by' => $point->filled_by,
                    'depth' => $depth,
                    'hasChildren' => $children->isNotEmpty(),
                    'checked' => $val?->checked_days ?? [],
                    'note' => $val?->note,
                ];
                foreach ($children as $i => $child) {
                    $walk($child, $number.'.'.($i + 1), $depth + 1);
                }
            };
            foreach ($tops as $i => $top) {
                $walk($top, $cat->sort_order.'.'.($i + 1), 0);
            }

            $sections[] = ['label' => $cat->label, 'sort_order' => $cat->sort_order, 'rows' => $rows];
        }

        $variances = VarianClinicalPathway::where('assessment_id', $asesmen->id)
            ->orderBy('occurred_at')
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
                'by' => $asesmen->doctor_verified_by,
                'at' => $asesmen->doctor_verified_at,
                'qr' => $verifQr($asesmen->doctor_verified_by),
            ],
            [
                'title' => 'Perawat Penanggung Jawab',
                'by' => $asesmen->nurse_verified_by,
                'at' => $asesmen->nurse_verified_at,
                'qr' => $verifQr($asesmen->nurse_verified_by),
            ],
            [
                'title' => 'Pelaksana Verifikasi',
                'by' => $asesmen->executor_verified_by,
                'at' => $asesmen->executor_verified_at,
                'qr' => $verifQr($asesmen->executor_verified_by),
            ],
        ];

        $pdf = Pdf::loadView('pdf.asesmen_clinical_pathway', [
            'asesmen' => $asesmen,
            'template' => $template,
            'maxDays' => $maxDays,
            'days' => $days,
            'sections' => $sections,
            'variances' => $variances,
            'verifs' => $verifs,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('asesmen-clinical-pathway-'.$asesmen->id.'.pdf');
    }

    /** Validasi payload asesmen (identitas pasien + data klinis + perawatan). */
    private function validateAsesmen(Request $request): array
    {
        return $request->validate([
            // Wajib: template_id (formulir), medical_record_no, patient_name, room_id. Sisanya opsional.
            'template_id' => 'required|integer|exists:clinical_pathway_templates,id',
            'medical_record_no' => 'required|string|max:255',
            'patient_name' => 'required|string|max:255',
            'gender' => ['nullable', Rule::in(AsesmenClinicalPathway::GENDER)],
            'birth_date' => 'nullable|date',
            'admission_diagnosis' => 'nullable|string|max:255',
            'primary_disease' => 'nullable|string|max:255',
            'comorbidity' => 'nullable|string|max:255',
            'complication' => 'nullable|string|max:255',
            'procedure' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'admitted_at' => 'nullable|date',
            'discharged_at' => 'nullable|date|after_or_equal:admitted_at',
            'length_of_stay' => 'nullable|integer|min:0',
            'care_plan' => 'nullable|string|max:255',
            'room_id' => 'required|integer|exists:rooms,id',
            'ward_class' => 'nullable|string|max:255',
            'is_referral' => 'sometimes|boolean',
        ]);
    }
}
