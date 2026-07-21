<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
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
 * cleaning. Saat "Selesai Cuci", washing ditandai `selesai` dan batch masuk
 * antrean tahap Packaging — record `packaging` sendiri baru dibuat ketika petugas
 * mulai inspeksi. Respons dibentuk agar cocok dengan tipe CleaningOrder di frontend.
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
        // includeAdvanced=true → sertakan juga washing yang sudah selesai (lanjut ke
        // packaging) sebagai riwayat cleaning; dibedakan lewat `stage_status`.
        $query = $this->cleaningQuery(true)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('production_code', 'like', "%{$s}%")
                    ->orWhere('operator', 'like', "%{$s}%"))
            );

        // Tanggal acuan tahap Cleaning: waktu selesai cuci bila sudah selesai, kalau
        // belum pakai waktu mulai cuci / mulai diproses, terakhir waktu record dibuat.
        $washings = $this->applyDateRange(
            $query,
            $request,
            'COALESCE(completed_at, washed_at, started_at, created_at)'
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
        // Field parameter pencucian WAJIB hanya pada aksi Simpan biasa. Aksi
        // "Selesai" (complete) mengirim payload minimal, dan "Tandai Gagal" (fail)
        // hanya butuh alasan — keduanya tidak boleh dipaksa mengisi parameter.
        $isSave = ! $request->boolean('complete') && ! $request->boolean('fail');
        $req = $isSave ? 'required' : 'nullable';

        $validated = $request->validate([
            // Mesin washer dirujuk lewat id — kolom kode/barcode WSH-NNN sudah dihapus.
            'washer_machine_id' => "$req|exists:washer_machines,id",
            'operator' => 'nullable|string|max:255',
            'temperature' => "$req|string|max:50",
            'washed_at' => "$req|date",
            'duration_minutes' => "$req|integer|min:0",
            'detergent_type' => "$req|string|max:255",
            'complete' => 'sometimes|boolean',
            'completed_at' => 'nullable|date',
            'fail' => 'sometimes|boolean',
            'failure_reason' => 'nullable|string|max:255',
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $washing) {
                $actor = auth()->user()?->name;

                // Tandai Gagal → HANYA penanda (wajib diulang). Tidak memproses/menyimpan
                // parameter pencucian dan tidak menyelesaikan tahap: parameter yang ada
                // dibiarkan apa adanya, cukup ubah status + alasan.
                if (! empty($validated['fail'])) {
                    $washing->status = OrderWashing::STATUS_GAGAL;
                    $washing->failure_reason = $validated['failure_reason'] ?? 'Pencucian gagal.';
                    $washing->save();

                    PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_GAGAL, [
                        'note' => 'Pencucian ditandai gagal: '.$washing->failure_reason,
                    ]);

                    return ['blocked' => false, 'failed' => true];
                }

                $washing->fill(array_intersect_key(
                    $validated,
                    array_flip(['washer_machine_id', 'operator', 'temperature', 'washed_at', 'duration_minutes', 'detergent_type'])
                ));

                // Yang pertama kali mengisi = penanggung jawab mulai (bila belum ada).
                $washing->started_by ??= $actor;
                $washing->started_at ??= now();

                // Evaluasi parameter terhadap ambang mesin washer yang dipilih.
                $alerts = [];
                if ($washing->washer_machine_id && ($machine = WasherMachine::find($washing->washer_machine_id))) {
                    $temp = is_numeric($washing->temperature) ? (float) $washing->temperature : null;
                    $alerts = $machine->evaluate($temp, $washing->duration_minutes);
                }
                $washing->alert = ! empty($alerts);
                $washing->alert_message = $alerts ? implode(' ', $alerts) : null;

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

                    // Record `packaging` + `packaging_item` TIDAK dibuat di sini.
                    // Batch masuk antrean tahap Packaging hanya lewat status washing
                    // `selesai`; recordnya baru dibuat saat petugas mulai inspeksi
                    // (PackagingController@start).
                    PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_SELESAI, [
                        'note' => 'Pencucian selesai — menunggu inspeksi & pengemasan',
                    ]);

                    // Perbarui tahap unit (→ pengemasan).
                    $stockIds = $washing->production?->items()->pluck('instrument_stock_id')->all() ?? [];
                    InstrumentStock::syncStages($stockIds);

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

    /**
     * Batalkan batch cleaning yang BELUM diproses (parameter pencucian belum diisi
     * & belum selesai). Batch TIDAK dihapus — statusnya menjadi `batal` dan tetap
     * tampil sebagai riwayat cleaning (mencatat siapa & kapan membatalkan). Seluruh
     * unit yang tadinya dipotong dikembalikan ke stok semula (`tersedia`).
     */
    public function cancelWashing(OrderWashing $washing): JsonResponse
    {
        if ($washing->status === OrderWashing::STATUS_SELESAI) {
            return $this->error('Pencucian sudah selesai dan tidak bisa dibatalkan.', 422);
        }
        if ($washing->status === OrderWashing::STATUS_BATAL) {
            return $this->error('Pencucian sudah dibatalkan.', 422);
        }

        // Sudah diproses (parameter terisi) → tidak boleh dibatalkan; gunakan
        // "Tandai Gagal" bila perlu diulang.
        if ($this->isWashingProcessed($washing)) {
            return $this->error('Pencucian sudah diproses. Tandai gagal bila perlu diulang, tidak bisa dibatalkan.', 422);
        }

        try {
            DB::transaction(function () use ($washing) {
                $production = $washing->production;

                if ($production) {
                    // Kembalikan stok unit terkunci ke status semula: `tersedia`.
                    $stockIds = $production->items()->pluck('instrument_stock_id')->all();
                    if (! empty($stockIds)) {
                        InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_TERSEDIA, [
                            'context' => 'production',
                            'reference' => $production->code,
                            'note' => 'Produksi dibatalkan — stok dikembalikan ke tersedia',
                        ]);
                    }

                    PipelineEvent::record(PipelineEvent::STAGE_PRODUCTION, $production->code, PipelineEvent::ACTION_BATAL, [
                        'note' => 'Batch produksi dibatalkan, '.count($stockIds).' unit dikembalikan ke stok',
                    ]);

                    // Hard delete batch produksi (item ikut terhapus via cascade DB)
                    // agar slot nomor PRD-nya kosong kembali & dipakai ulang produksi
                    // berikutnya. Production tidak memakai HasAuditColumns, jadi
                    // delete() di sini sudah hard delete (bukan soft delete).
                    $production->delete();
                }

                // Catat pembatalan di jejak pipeline (audit terpisah) sebelum record dihapus.
                PipelineEvent::record(PipelineEvent::STAGE_WASHING, $washing->code, PipelineEvent::ACTION_BATAL, [
                    'note' => 'Cleaning dibatalkan sebelum diproses — record dihapus permanen',
                ]);

                // Hapus permanen record cleaning: pembatalan tidak menyisakan riwayat
                // di database sama sekali (bukan hanya ditandai batal).
                $washing->forceDelete();
            });

            return $this->success('Pencucian dibatalkan, stok dikembalikan & record dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * "Sudah diproses" = operator telah mengisi salah satu parameter pencucian.
     * Sejalan dengan `isWashingFilled()` di frontend.
     */
    private function isWashingProcessed(OrderWashing $washing): bool
    {
        return (bool) ($washing->washer_machine_id
            || $washing->operator
            || $washing->temperature
            || $washing->washed_at
            || $washing->duration_minutes
            || $washing->detergent_type);
    }

    /**
     * Query dasar batch cleaning. Default: hanya washing yang masih di tahap
     * cleaning (belum selesai). `$includeAdvanced=true` menyertakan juga yang
     * sudah selesai (riwayat cleaning).
     *
     * Dulu penanda "sudah lanjut" adalah keberadaan record packaging; sejak record
     * packaging baru dibuat saat inspeksi dimulai, penandanya status washing.
     */
    private function cleaningQuery(bool $includeAdvanced = false)
    {
        $query = OrderWashing::with([
            'washerMachine',
            'production.items.instrumentStock.instrument',
            'production.items.conditionOut',
        ]);

        if (! $includeAdvanced) {
            $query->where('status', '!=', OrderWashing::STATUS_SELESAI);
        }

        return $query;
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
        // Nama instrumen dibaca dari snapshot production_item.name, BUKAN relasi ke
        // master — riwayat batch harus tetap sama walau master berubah/dihapus.
        $items = $units
            ->groupBy(fn ($u) => $u->source === 'paket'
                ? 'paket|'.($u->package_name ?? 'Paket')
                : 'satuan|'.($u->name ?? 'Instrumen'))
            ->map(function ($group) {
                $first = $group->first();
                $isPaket = $first->source === 'paket';

                return [
                    'type' => $isPaket ? 'paket' : 'satuan',
                    'name' => $isPaket
                        ? ($first->package_name ?? 'Paket')
                        : ($first->name ?? 'Instrumen'),
                    // Paket dihitung per SET (package_no), bukan per instrumen di
                    // dalamnya — 2 set partus berisi 6 instrumen = 2, bukan 12. Batch
                    // lama tanpa package_no (null) melebur jadi satu set, sama seperti
                    // pengelompokan daftar instrumen di frontend.
                    'quantity' => $isPaket
                        ? $group->pluck('package_no')->unique()->count()
                        : $group->count(),
                ];
            })
            ->values();

        $jenis = $units->pluck('name')->filter()->unique()->count();

        return [
            'id' => $washing->id,
            'code' => $washing->code,                          // WSH+ymd+urutan harian (id record cleaning)
            'code_transaction' => $production?->code ?? $washing->production_code, // PRD-NNN (fallback ke kode tersimpan bila batch batal sudah dihapus)
            'status' => 'pencucian',
            'stage_status' => match ($washing->status) { // proses | selesai | batal (riwayat)
                OrderWashing::STATUS_SELESAI => 'selesai',
                OrderWashing::STATUS_BATAL => 'batal',
                default => 'proses',
            },
            'borrowed_by' => $production?->displayName(),
            'note' => $production?->note, // Catatan (opsional) yang diisi saat Mulai Produksi.
            'room' => null,
            'order_date' => $production?->created_at?->toDateString(),
            'processed_at' => $production?->created_at ?? $washing->started_at,
            'processed_by' => $production?->created_by ?? $washing->started_by,
            'requested_qty' => $units->count(),
            'request_lines' => $jenis,
            'items' => $items,
            'units_count' => $units->count(),
            'units' => $units->map(fn ($u) => [
                'id' => $u->id,
                'source' => $u->source,
                'package_name' => $u->package_name,
                // Set ke-berapa dalam batch — dua set bernama sama harus tampil
                // sebagai dua kelompok terpisah, bukan melebur jadi satu.
                'package_no' => $u->package_no,
                'instrument_stock_id' => $u->instrument_stock_id,
                // Nama, kode & foto unit seluruhnya dari snapshot production_item
                // (dibekukan saat batch dibuat). `image_url` = foto paket untuk baris
                // paket, foto instrumen untuk baris satuan. Relasi ke master hanya
                // jadi cadangan untuk batch lama yang dibuat sebelum kolom snapshot ada.
                'name' => $u->name ?? $u->instrumentStock?->instrument?->name,
                'code' => $u->kode_instrumen ?? $u->instrumentStock?->code,
                'image_url' => $u->image_url ?? $u->instrumentStock?->instrument?->image_url,
                'status' => $u->instrumentStock?->status,
                'condition_out' => $u->conditionOut
                    ? ['id' => $u->conditionOut->id, 'name' => $u->conditionOut->name]
                    : null,
            ])->values(),
            'washing' => [
                'washer_machine_id' => $washing->washer_machine_id,
                'washer_machine' => $washing->washerMachine ? [
                    'id' => $washing->washerMachine->id,
                    'name' => $washing->washerMachine->name,
                ] : null,
                'operator' => $washing->operator,
                'temperature' => $washing->temperature,
                'washed_at' => $washing->washed_at,
                'duration_minutes' => $washing->duration_minutes,
                'detergent_type' => $washing->detergent_type,
                'status' => $washing->status,
                'alert' => $washing->alert,
                'alert_message' => $washing->alert_message,
                'failure_reason' => $washing->failure_reason,
                // Jejak pelaku tiap aksi + waktunya.
                'started_by' => $washing->started_by,     // yang memproses
                'started_at' => $washing->started_at,
                'completed_by' => $washing->completed_by,  // yang menyelesaikan
                'completed_at' => $washing->completed_at,
                'canceled_by' => $washing->canceled_by,    // yang membatalkan
                'canceled_at' => $washing->canceled_at,
            ],
        ];
    }
}
