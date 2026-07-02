<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderWashing;
use App\Models\Packaging;
use App\Models\PipelineEvent;
use App\Models\WasherMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tahap Cleaning & Disinfection pada pipeline pemrosesan CSSD.
 *
 * Sumber data kini tabel `washing` (dirangkai dari `production` lewat
 * production_code), bukan lagi order. Satu record washing = satu batch di tahap
 * cleaning. Saat "Selesai Cuci", dibuat record `packaging` (PKG) sebagai tahap
 * berikutnya. Respons dibentuk agar cocok dengan tipe CleaningOrder di frontend.
 */
class CleaningController extends Controller
{
    /**
     * [ALUR ORDER PINJAMAN — belum dimigrasi ke pipeline baru]
     * Proses order masuk: pindahkan dari "diajukan" ke tahap pencucian.
     */
    public function process(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_DIAJUKAN) {
            return $this->error('Order ini sudah diproses dan tidak bisa diproses lagi.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $order->status = Order::STATUS_PENCUCIAN;
                $order->processed_at = now();
                $order->processed_by = auth()->user()?->name;
                $order->save();

                OrderEvent::record(OrderEvent::TYPE_DIPROSES, $order, [
                    'note' => 'Order diproses & masuk tahap Cleaning',
                ]);
            });

            $order->load(['room']);

            return $this->success('Order berhasil diproses & masuk tahap Cleaning.', $order);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Daftar batch pada tahap Cleaning: record `washing` yang belum lanjut ke
     * packaging (belum ada record packaging dengan washing_code-nya).
     */
    public function index(Request $request): JsonResponse
    {
        $washings = $this->cleaningQuery()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('production_code', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%"))
            )
            ->orderByDesc('id')
            ->paginate(20);

        $washings->getCollection()->transform(fn (OrderWashing $w) => $this->transform($w));

        return $this->success('Data tahap cleaning berhasil diambil.', $washings);
    }

    /**
     * Daftar notifikasi kegagalan pencucian: washing dengan alert parameter
     * (suhu/durasi di luar ambang mesin washer).
     */
    public function alerts(Request $request): JsonResponse
    {
        $washings = $this->cleaningQuery()
            ->where('alert', true)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('production_code', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%"))
            )
            ->orderByDesc('id')
            ->paginate(20);

        $washings->getCollection()->transform(fn (OrderWashing $w) => $this->transform($w));

        return $this->success('Daftar notifikasi kegagalan pencucian berhasil diambil.', $washings);
    }

    /**
     * Simpan / perbarui catatan pencucian sebuah record washing.
     *
     * - `washer_machine_id` mencatat mesin washer; suhu & durasi dievaluasi
     *   terhadap ambang mesin → bila di luar rentang, ditandai `alert`.
     * - `complete = true` menandai "Selesai Cuci" → dibuat tahap packaging (PKG).
     *   Tidak diizinkan selama masih ada alert parameter.
     * - `fail = true` menandai pencucian "Gagal" (wajib diulang).
     */
    public function updateWashing(Request $request, OrderWashing $washing): JsonResponse
    {
        $validated = $request->validate([
            'washer_machine_id' => 'nullable|exists:washer_machines,id',
            'machine_no' => 'nullable|string|max:255',
            'operator' => 'nullable|string|max:255',
            'temperature' => 'nullable|string|max:50',
            'washed_at' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:0',
            'detergent_type' => 'nullable|string|max:255',
            'complete' => 'sometimes|boolean',
            'completed_at' => 'nullable|date',
            'fail' => 'sometimes|boolean',
            'failure_reason' => 'nullable|string|max:255',
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $washing) {
                $actor = auth()->user()?->name;

                $washing->fill(array_intersect_key(
                    $validated,
                    array_flip(['washer_machine_id', 'machine_no', 'operator', 'temperature', 'washed_at', 'duration_minutes', 'detergent_type'])
                ));

                // Yang pertama kali mengisi = penanggung jawab mulai (bila belum ada).
                $washing->started_by ??= $actor;
                $washing->started_at ??= now();

                // Evaluasi parameter terhadap ambang mesin washer yang dipindai.
                $alerts = [];
                if ($washing->washer_machine_id && ($machine = WasherMachine::find($washing->washer_machine_id))) {
                    $temp = is_numeric($washing->temperature) ? (float) $washing->temperature : null;
                    $alerts = $machine->evaluate($temp, $washing->duration_minutes);
                    // Lengkapi nomor mesin dari master bila belum diisi manual.
                    $washing->machine_no ??= $machine->code;
                }
                $washing->alert = ! empty($alerts);
                $washing->alert_message = $alerts ? implode(' ', $alerts) : null;

                // Tandai Gagal → wajib diulang.
                if (! empty($validated['fail'])) {
                    $washing->status = OrderWashing::STATUS_GAGAL;
                    $washing->failure_reason = $validated['failure_reason']
                        ?? $washing->alert_message
                        ?? 'Pencucian gagal.';
                    $washing->save();

                    PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_GAGAL, [
                        'note' => 'Pencucian gagal: '.$washing->failure_reason,
                    ]);

                    return ['blocked' => false, 'failed' => true];
                }

                $completing = ! empty($validated['complete']);

                // Selesai Cuci diblokir selama parameter masih di luar ambang mesin.
                if ($completing && $washing->alert) {
                    $washing->save();

                    return ['blocked' => true, 'failed' => false];
                }

                if ($completing) {
                    $completedAt = $validated['completed_at'] ?? now();
                    $washing->status = OrderWashing::STATUS_SELESAI;
                    $washing->completed_at = $completedAt;
                    $washing->completed_by = $actor;
                    $washing->washed_at ??= $completedAt;
                    $washing->failure_reason = null;
                    $washing->save();

                    // Buka tahap Packaging (PKG) — dirangkai lewat washing_code.
                    $packaging = Packaging::create([
                        'washing_code' => $washing->code,
                        'status' => Packaging::STATUS_DIPROSES,
                        'started_by' => $actor,
                        'started_at' => now(),
                    ]);

                    PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_SELESAI, [
                        'note' => 'Pencucian selesai',
                    ]);
                    PipelineEvent::record(PipelineEvent::STAGE_PACKAGING, $packaging->code, PipelineEvent::ACTION_DIBUAT, [
                        'note' => 'Masuk tahap Packaging (dari cleaning '.$washing->code.')',
                    ]);

                    return ['blocked' => false, 'failed' => false];
                }

                // Disimpan ulang setelah perbaikan parameter → kembali Dalam Proses.
                if ($washing->status === OrderWashing::STATUS_GAGAL) {
                    $washing->status = OrderWashing::STATUS_DALAM_PROSES;
                    $washing->failure_reason = null;
                }

                $washing->save();

                return ['blocked' => false, 'failed' => false];
            });

            $washing->refresh();

            if ($result['blocked']) {
                return $this->error(
                    'Parameter pencucian di luar ambang mesin: '.$washing->alert_message.' Periksa ulang atau tandai gagal.',
                    422
                );
            }

            $message = $result['failed']
                ? 'Pencucian ditandai gagal dan harus diulang.'
                : 'Catatan pencucian berhasil disimpan.';

            return $this->success($message, $this->transform($washing));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Query dasar batch cleaning: washing yang belum lanjut ke packaging. */
    private function cleaningQuery()
    {
        return OrderWashing::with([
            'washerMachine',
            'production.items.instrumentStock.instrument',
            'production.items.conditionOut',
        ])->whereNotIn('code', Packaging::query()
            ->whereNotNull('washing_code')
            ->select('washing_code'));
    }

    /** Bentuk respons satu batch cleaning agar cocok dengan tipe CleaningOrder di frontend. */
    private function transform(OrderWashing $washing): array
    {
        $washing->loadMissing([
            'washerMachine',
            'production.items.instrumentStock.instrument',
            'production.items.conditionOut',
        ]);

        $production = $washing->production;
        $units = $production ? $production->items : collect();

        // Ringkasan untuk chip kartu: unit paket dikelompokkan PER PAKET (nama paket,
        // bukan dipecah per instrumen penyusunnya); unit satuan per nama instrumen.
        $items = $units
            ->groupBy(fn ($u) => $u->source === 'paket'
                ? 'paket|'.($u->package_name ?? 'Paket')
                : 'satuan|'.($u->instrumentStock?->instrument?->name ?? 'Instrumen'))
            ->map(function ($group) {
                $first = $group->first();
                $isPaket = $first->source === 'paket';

                return [
                    'type' => $isPaket ? 'paket' : 'satuan',
                    'name' => $isPaket
                        ? ($first->package_name ?? 'Paket')
                        : ($first->instrumentStock?->instrument?->name ?? 'Instrumen'),
                    'quantity' => $group->count(),
                ];
            })
            ->values();

        $jenis = $units->pluck('instrumentStock.instrument.id')->filter()->unique()->count();

        return [
            'id' => $washing->id,
            'code' => $washing->code,                          // WSH-NNN (id record cleaning)
            'code_transaction' => $production?->code,          // PRD-NNN (ditampilkan di kartu)
            'status' => 'pencucian',
            'borrowed_by' => $production?->displayName(),
            'room' => null,
            'order_date' => $production?->created_at?->toDateString(),
            'processed_at' => $production?->completed_at ?? $washing->started_at,
            'processed_by' => $production?->completed_by ?? $washing->started_by,
            'requested_qty' => $units->count(),
            'request_lines' => $jenis,
            'items' => $items,
            'units_count' => $units->count(),
            'units' => $units->map(fn ($u) => [
                'id' => $u->id,
                'source' => $u->source,
                'package_name' => $u->package_name,
                'instrument_stock_id' => $u->instrument_stock_id,
                'code' => $u->instrumentStock?->code,
                'instrument' => $u->instrumentStock?->instrument
                    ? ['id' => $u->instrumentStock->instrument->id, 'name' => $u->instrumentStock->instrument->name]
                    : null,
                'status' => $u->instrumentStock?->status,
                'condition_out' => $u->conditionOut
                    ? ['id' => $u->conditionOut->id, 'name' => $u->conditionOut->name]
                    : null,
            ])->values(),
            'washing' => [
                'washer_machine_id' => $washing->washer_machine_id,
                'washer_machine' => $washing->washerMachine ? [
                    'id' => $washing->washerMachine->id,
                    'code' => $washing->washerMachine->code,
                    'name' => $washing->washerMachine->name,
                ] : null,
                'machine_no' => $washing->machine_no,
                'operator' => $washing->operator,
                'temperature' => $washing->temperature,
                'washed_at' => $washing->washed_at,
                'duration_minutes' => $washing->duration_minutes,
                'detergent_type' => $washing->detergent_type,
                'status' => $washing->status,
                'alert' => $washing->alert,
                'alert_message' => $washing->alert_message,
                'failure_reason' => $washing->failure_reason,
                'completed_at' => $washing->completed_at,
            ],
        ];
    }
}
