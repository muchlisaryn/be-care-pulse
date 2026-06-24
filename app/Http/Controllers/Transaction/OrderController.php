<?php

namespace App\Http\Controllers\Transaction;

use App\Events\OrderSubmitted;
use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /** Relasi yang dimuat saat menampilkan detail order. */
    private const DETAIL_RELATIONS = [
        'room',
        'user',
        // Baris permintaan (jumlah) yang diinput peminjam.
        'requestItems.instrument',
        'requestItems.catalog',
        // Isi paket (instrumen apa saja di dalam katalog) untuk ditampilkan di detail.
        'requestItems.catalog.items.instrument',
        // Unit fisik hasil generate saat order diterima (kosong sebelum diterima).
        'items.instrumentStock.instrument',
        'items.conditionOut',
        'items.conditionIn',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = Order::with(['room', 'user'])
            ->withCount([
                'items',
                // Jumlah unit per asal — dipakai frontend untuk menandai jenis order
                // (paket / satuan / campuran) di halaman daftar order masuk.
                'items as paket_items_count' => fn ($q) => $q->where('source', 'paket'),
                'items as satuan_items_count' => fn ($q) => $q->where('source', 'satuan'),
            ])
            // Hanya tampilkan order milik akun yang login (penanggung jawab order).
            ->where('user_id', auth()->id())
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(
                $request->search,
                // Bungkus dalam grup agar OR tidak membocorkan order milik akun lain.
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($q) => $q->where('name', 'like', "%{$s}%")))
            )
            ->latest()
            ->paginate(20);

        return $this->success('Data peminjaman berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'borrowed_by' => 'nullable|string|max:255',
            'order_date' => 'required|date',
            'order_time' => 'nullable|date_format:H:i',
            'return_plan_date' => 'nullable|date',
            'note' => 'nullable|string',
            // Baris permintaan: hanya jumlah. Unit fisik (order_item) di-generate
            // kemudian saat CSSD menerima pesanan.
            'items' => 'required|array|min:1',
            'items.*.type' => ['required', Rule::in(['satuan', 'paket'])],
            'items.*.quantity' => 'required|integer|min:1',
            // Satuan → wajib instrument_id; Paket → wajib instrument_catalog_id.
            'items.*.instrument_id' => 'required_if:items.*.type,satuan|nullable|integer|exists:instruments,id',
            'items.*.instrument_catalog_id' => 'required_if:items.*.type,paket|nullable|integer|exists:instrument_catalogs,id',
            'items.*.package_name' => 'nullable|string|max:255',
        ]);

        try {
            $order = DB::transaction(function () use ($validated) {
                $order = Order::create([
                    'room_id' => $validated['room_id'],
                    // Penanggung jawab default = user yang login bila tidak dikirim eksplisit.
                    'user_id' => $validated['user_id'] ?? auth()->id(),
                    // Nama peminjam (teks bebas) — diisi manual di form.
                    'borrowed_by' => $validated['borrowed_by'] ?? null,
                    'order_date' => $validated['order_date'],
                    'order_time' => $validated['order_time'] ?? null,
                    'return_plan_date' => $validated['return_plan_date'] ?? null,
                    'note' => $validated['note'] ?? null,
                    'status' => Order::STATUS_DIAJUKAN,
                ]);

                foreach ($validated['items'] as $item) {
                    $isPaket = $item['type'] === 'paket';
                    $order->requestItems()->create([
                        'type' => $item['type'],
                        'instrument_id' => $isPaket ? null : ($item['instrument_id'] ?? null),
                        'instrument_catalog_id' => $isPaket ? ($item['instrument_catalog_id'] ?? null) : null,
                        'package_name' => $isPaket ? ($item['package_name'] ?? null) : null,
                        'quantity' => $item['quantity'],
                    ]);
                }

                // Timeline: order dibuat (code_transaction masih null, diisi saat diterima).
                OrderEvent::record(OrderEvent::TYPE_DIBUAT, $order, [
                    'note' => 'Order peminjaman diajukan',
                ]);

                return $order;
            });

            $order->load(self::DETAIL_RELATIONS);

            // Siarkan ke channel real-time agar CSSD langsung dapat notifikasi
            // order baru (bunyi + badge) tanpa menunggu polling.
            broadcast(new OrderSubmitted($order));

            return $this->success('Peminjaman berhasil dibuat.', $order, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(self::DETAIL_RELATIONS);
        // Lampirkan Riwayat Peminjaman (timeline) untuk ditampilkan di detail order.
        $this->attachTimeline($order);

        return $this->success('Detail peminjaman berhasil diambil.', $order);
    }

    /**
     * Daftar order yang sedang dipinjam (oleh ruangan mana pun) beserta unit yang
     * belum dikembalikan. Dipakai halaman "Pinjam Instrumen": sumber unit yang bisa
     * diminta pinjam-alih antar ruangan tanpa order ulang ke CSSD. Pembeda peminjam
     * adalah RUANGAN (bukan akun user) — sistem dapat berjalan dengan satu akun.
     */
    public function borrowable(Request $request): JsonResponse
    {
        // Nama & akun peminjam yang sedang login — dipakai untuk menyembunyikan
        // pinjaman milik sendiri (tidak bisa meminjam dari diri sendiri).
        $myId = auth()->id();
        $myName = auth()->user()?->name;

        $orders = Order::with([
            'room',
            'user',
            'items' => fn ($q) => $q->where('is_returned', false)
                ->with('instrumentStock.instrument'),
        ])
            ->where('status', Order::STATUS_DIPINJAM)
            // Kecualikan pinjaman milik peminjam yang sedang login. Peminjam efektif =
            // borrowed_by bila terisi, jika kosong jatuh ke pemilik order (user).
            ->where(function ($w) use ($myId, $myName) {
                $w->where(function ($a) use ($myName) {
                    $a->whereNotNull('borrowed_by')->where('borrowed_by', '!=', (string) $myName);
                })->orWhere(function ($b) use ($myId) {
                    $b->whereNull('borrowed_by')->where('user_id', '!=', $myId);
                });
            })
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest()
            ->get()
            // Hanya order yang masih punya unit aktif.
            ->filter(fn (Order $o) => $o->items->isNotEmpty())
            ->values();

        // Petakan permintaan pinjam yang masih `pending` per instrument_stock_id,
        // agar frontend bisa menandai unit yang sudah diminta (cegah request dobel).
        $pendingByStock = [];
        $pendingTransfers = \App\Models\OrderTransfer::with(['toRoom', 'requestedBy', 'items'])
            ->whereIn('from_order_id', $orders->pluck('id'))
            ->where('status', \App\Models\OrderTransfer::STATUS_PENDING)
            ->get();
        foreach ($pendingTransfers as $t) {
            foreach ($t->items as $ti) {
                $pendingByStock[$ti->instrument_stock_id] = [
                    'transfer_id' => $t->id,
                    'to_room' => $t->toRoom?->name,
                    'borrowed_by' => $t->borrowed_by,
                    'requested_by' => $t->requestedBy?->name,
                    'is_mine' => (int) $t->requested_by_user_id === (int) auth()->id(),
                ];
            }
        }

        $data = $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'code' => $o->code,
            'code_transaction' => $o->code_transaction,
            'borrowed_by' => $o->borrowed_by ?? $o->user?->name,
            'room' => $o->room ? ['id' => $o->room->id, 'name' => $o->room->name] : null,
            'order_date' => $o->order_date,
            'order_time' => $o->order_time,
            'return_plan_date' => $o->return_plan_date,
            'units' => $o->items->map(fn ($it) => [
                'order_item_id' => $it->id,
                'instrument_stock_id' => $it->instrument_stock_id,
                'code' => $it->instrumentStock?->code,
                'instrument_name' => $it->instrumentStock?->instrument?->name,
                'source' => $it->source,
                'package_name' => $it->package_name,
                // Info permintaan pinjam yang masih menunggu ACC untuk unit ini (atau null).
                'pending_transfer' => $pendingByStock[$it->instrument_stock_id] ?? null,
            ])->values(),
        ])->values();

        return $this->success('Daftar instrumen yang bisa dipinjam berhasil diambil.', $data);
    }

    /**
     * Susun kebutuhan unit fisik dari baris permintaan order (satuan + isi paket),
     * dikelompokkan per (instrumen, asal, nama paket). Dipakai bersama oleh
     * `allocation()` (menampilkan pilihan unit) dan `receive()` (validasi).
     *
     * @return array<string, array{key:string,source:string,package_name:?string,instrument_id:int,needed_qty:int}>
     */
    private function buildRequirements(Order $order): array
    {
        $order->loadMissing(['requestItems.instrument', 'requestItems.catalog.items.instrument']);

        $reqs = [];
        $add = function (string $source, ?string $packageName, $instrument, int $qty) use (&$reqs) {
            if (! $instrument || $qty <= 0) {
                return;
            }
            $key = $source.'|'.$instrument->id.'|'.($packageName ?? '');
            $reqs[$key] ??= [
                'key' => $key,
                'source' => $source,
                'package_name' => $packageName,
                'instrument_id' => $instrument->id,
                'instrument' => [
                    'id' => $instrument->id,
                    'code' => $instrument->code,
                    'name' => $instrument->name,
                ],
                'needed_qty' => 0,
            ];
            $reqs[$key]['needed_qty'] += $qty;
        };

        foreach ($order->requestItems as $line) {
            if ($line->type === 'paket') {
                $packageName = $line->package_name ?? $line->catalog?->name ?? 'Paket';
                foreach (($line->catalog?->items ?? []) as $ci) {
                    $add('paket', $packageName, $ci->instrument, $line->quantity * $ci->quantity);
                }
            } else {
                $add('satuan', null, $line->instrument, $line->quantity);
            }
        }

        return $reqs;
    }

    /**
     * Data alokasi untuk menerima order: kebutuhan unit per instrumen + daftar
     * unit yang masih `tersedia` untuk dipilih/generate di frontend.
     */
    public function allocation(Order $order): JsonResponse
    {
        if (! in_array($order->status, [Order::STATUS_DIAJUKAN], true)) {
            return $this->error('Order ini sudah diproses dan tidak bisa diterima lagi.', 422);
        }

        $requirements = $this->buildRequirements($order);

        // Ambil semua unit tersedia untuk instrumen yang dibutuhkan dalam satu query.
        $instrumentIds = collect($requirements)->pluck('instrument_id')->unique()->all();
        $availableByInstrument = InstrumentStock::whereIn('instrument_id', $instrumentIds)
            ->where('status', InstrumentStock::STATUS_TERSEDIA)
            ->orderBy('code')
            ->get(['id', 'code', 'instrument_id'])
            ->groupBy('instrument_id');

        $requirements = collect($requirements)->values()->map(function ($req) use ($availableByInstrument) {
            $units = ($availableByInstrument[$req['instrument_id']] ?? collect())
                ->map(fn ($u) => ['id' => $u->id, 'code' => $u->code])
                ->values();

            return [
                ...$req,
                'available_units' => $units,
                'available_count' => $units->count(),
            ];
        });

        $order->loadMissing('room');

        return $this->success('Data alokasi order berhasil diambil.', [
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'borrowed_by' => $order->borrowed_by,
                'order_date' => $order->order_date,
                'order_time' => $order->order_time,
                'return_plan_date' => $order->return_plan_date,
                'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            ],
            'requirements' => $requirements,
        ]);
    }

    /**
     * Terima order: alokasikan unit fisik terpilih → buat order_item, ubah status
     * order menjadi `dipinjam`, dan transisikan stok unit ke `dipinjam` (mengurangi
     * stok tersedia). Sekaligus boleh memperbarui penerima & tanggal.
     */
    public function receive(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, [Order::STATUS_DIAJUKAN], true)) {
            return $this->error('Order ini sudah diproses dan tidak bisa diterima lagi.', 422);
        }

        $validated = $request->validate([
            'borrowed_by' => 'nullable|string|max:255',
            'order_date' => 'required|date',
            'order_time' => 'nullable|date_format:H:i',
            'return_plan_date' => 'nullable|date',
            // Peta: key requirement → daftar instrument_stock_id yang dipilih.
            'selections' => 'required|array|min:1',
            'selections.*' => 'array',
            'selections.*.*' => 'integer',
        ]);

        try {
            DB::transaction(function () use ($validated, $order) {
                $requirements = $this->buildRequirements($order);

                if (empty($requirements)) {
                    throw new \RuntimeException('Order tidak punya baris permintaan yang bisa dialokasikan.');
                }

                $selections = $validated['selections'];
                $allStockIds = [];

                foreach ($requirements as $key => $req) {
                    $ids = array_values(array_filter($selections[$key] ?? []));

                    if (count($ids) !== $req['needed_qty']) {
                        throw new \RuntimeException(
                            "Jumlah unit untuk \"{$req['instrument']['name']}\" harus {$req['needed_qty']}, "
                            ."terisi ".count($ids).'.'
                        );
                    }

                    $stocks = InstrumentStock::whereIn('id', $ids)->get()->keyBy('id');

                    foreach ($ids as $id) {
                        $stock = $stocks->get($id);
                        if (! $stock) {
                            throw new \RuntimeException("Unit #{$id} tidak ditemukan.");
                        }
                        if ((int) $stock->instrument_id !== (int) $req['instrument_id']) {
                            throw new \RuntimeException("Unit \"{$stock->code}\" bukan milik instrumen \"{$req['instrument']['name']}\".");
                        }
                        if ($stock->status !== InstrumentStock::STATUS_TERSEDIA) {
                            throw new \RuntimeException("Unit \"{$stock->code}\" tidak tersedia (status: {$stock->status}).");
                        }
                        if (in_array($id, $allStockIds, true)) {
                            throw new \RuntimeException("Unit \"{$stock->code}\" terpilih lebih dari sekali.");
                        }

                        $allStockIds[] = $id;

                        $order->items()->create([
                            'instrument_stock_id' => $id,
                            'source' => $req['source'],
                            'package_name' => $req['package_name'],
                            // Kondisi keluar = kondisi unit saat dipinjamkan.
                            'condition_out_id' => $stock->condition_id,
                            'is_returned' => false,
                        ]);
                    }
                }

                // Perbarui header order + status, lalu kurangi stok (unit → dipinjam).
                $order->borrowed_by = $validated['borrowed_by'] ?? $order->borrowed_by;
                $order->order_date = $validated['order_date'];
                $order->order_time = $validated['order_time'] ?? $order->order_time;
                $order->return_plan_date = $validated['return_plan_date'] ?? null;
                $order->status = Order::STATUS_DIPINJAM;
                // Generate kode transaksi unik untuk barcode saat order diterima.
                if (! $order->code_transaction) {
                    $order->code_transaction = $this->generateTransactionCode();
                }
                $order->save();

                InstrumentStock::transitionMany($allStockIds, InstrumentStock::STATUS_DIPINJAM, [
                    'context' => 'order',
                    'reference' => $order->code,
                    'note' => 'order diterima',
                ]);

                // Backfill kode transaksi ke event lama order ini (mis. "dibuat"),
                // lalu catat event "diterima" (order di-ACC & dipinjamkan CSSD).
                OrderEvent::where('order_id', $order->id)
                    ->whereNull('code_transaction')
                    ->update(['code_transaction' => $order->code_transaction]);
                OrderEvent::record(OrderEvent::TYPE_DITERIMA, $order, [
                    'note' => 'Order diterima & unit dipinjamkan CSSD',
                ]);
            });

            $order->load(self::DETAIL_RELATIONS);

            return $this->success('Order berhasil diterima. Unit instrumen telah dialokasikan.', $order);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Scan untuk tracking berbasis order. Menerima:
     * - Kode order (ORD-NNN) → tampilkan order tersebut.
     * - Kode unit alat (mis. KLL-002) → cari order TERAKHIR yang memuat unit itu.
     * Dipakai halaman Scan & Tracking; QR unit yang sudah tercetak tetap bisa dipakai.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $code = $validated['code'];

        // 1. Coba sebagai kode order (ORD-xxx) atau kode transaksi barcode (INV...).
        $order = Order::where('code', $code)
            ->orWhere('code_transaction', $code)
            ->with(self::DETAIL_RELATIONS)
            ->first();

        // 2. Bukan order → coba sebagai kode unit alat, ambil order terakhirnya.
        if (! $order) {
            $stock = InstrumentStock::where('code', $code)->first();
            if ($stock) {
                $order = Order::whereHas('items', fn ($q) => $q->where('instrument_stock_id', $stock->id))
                    ->with(self::DETAIL_RELATIONS)
                    ->latest()
                    ->first();

                if (! $order) {
                    return $this->error("Unit \"{$code}\" belum pernah masuk order manapun.", 404);
                }
            }
        }

        if (! $order) {
            return $this->error("Order atau unit dengan kode \"{$code}\" tidak ditemukan.", 404);
        }

        $this->attachTimeline($order);

        return $this->success('Detail order berhasil diambil.', $order);
    }

    /**
     * Lampirkan timeline tracking ke order: seluruh event (dibuat/diterima/dipindah/
     * dikembalikan) untuk semua order yang berbagi code_transaction (rantai handover).
     * Bila order belum punya code_transaction, ambil event order itu saja.
     */
    private function attachTimeline(Order $order): void
    {
        if ($order->code_transaction) {
            $orderIds = Order::where('code_transaction', $order->code_transaction)->pluck('id');
        } else {
            $orderIds = collect([$order->id]);
        }

        $events = OrderEvent::with('room')
            ->whereIn('order_id', $orderIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (OrderEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'room' => $e->room?->name,
                'actor' => $e->actor,
                'borrowed_by' => $e->borrowed_by,
                'note' => $e->note,
                'created_at' => $e->created_at,
            ]);

        $order->setAttribute('timeline', $events);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Order::STATUSES)],
            'return_plan_date' => 'sometimes|nullable|date',
            'return_actual_date' => 'sometimes|nullable|date',
            'returned_by' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
            'items' => 'sometimes|array',
            'items.*.id' => 'required_with:items|integer',
            'items.*.is_returned' => 'sometimes|boolean',
            'items.*.condition_in_id' => 'sometimes|nullable|integer|exists:conditions,id',
        ]);

        try {
            DB::transaction(function () use ($validated, $order) {
                $order->fill(array_intersect_key($validated, array_flip(['return_plan_date', 'return_actual_date', 'note'])));

                // Nama orang yang mengembalikan — dipakai saat unit/order dikembalikan.
                $returnedBy = $validated['returned_by'] ?? null;

                // Perubahan status header + sinkronisasi status unit instrumen.
                if (isset($validated['status'])) {
                    $this->applyStatusTransition($order, $validated['status'], $returnedBy);
                }

                $order->save();

                // Pengembalian per-unit.
                if (! empty($validated['items'])) {
                    $this->processReturns($order, $validated['items'], $returnedBy);
                }
            });

            $order->load(self::DETAIL_RELATIONS);
            // Lampirkan timeline agar Riwayat Peminjaman tetap tampil setelah
            // pengembalian (termasuk pengembalian dicicil sebagian unit).
            $this->attachTimeline($order);

            return $this->success('Peminjaman berhasil diperbarui.', $order);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function destroy(Order $order): JsonResponse
    {
        // Hapus = soft delete (isi deleted_at/deleted_by via trait HasAuditColumns,
        // tidak pernah hard delete). Berbeda dari "batal" yang hanya mengubah status
        // ke "dibatalkan" + mengisi canceled_at/canceled_by (lihat update()).
        // Order yang sudah diproses (unit fisik dialokasikan) tidak boleh dihapus.
        if (in_array($order->status, [Order::STATUS_DIPINJAM, Order::STATUS_DIKEMBALIKAN], true)) {
            return $this->error('Order yang sudah diproses tidak bisa dihapus.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                // Bila order masih menahan unit (status dipinjam), kembalikan unitnya ke
                // `tersedia` sebelum dihapus agar tidak nyangkut permanen.
                if ($order->status === Order::STATUS_DIPINJAM) {
                    InstrumentStock::transitionMany(
                        $order->items()->pluck('instrument_stock_id'),
                        InstrumentStock::STATUS_TERSEDIA,
                        ['context' => 'order', 'reference' => $order->code, 'note' => 'order dihapus'],
                    );
                }

                $order->delete();
            });

            return $this->success('Peminjaman berhasil dihapus.');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Generate kode transaksi unik untuk barcode: INV + tahun + bulan + hari +
     * nomor urut order pada hari itu (3 digit). Contoh: INV20260619001.
     */
    private function generateTransactionCode(): string
    {
        $prefix = 'INV'.now()->format('Ymd');
        // Hitung order yang sudah punya kode transaksi pada hari yang sama
        // (termasuk yang sudah soft-deleted) agar nomor urut tetap unik.
        $seq = Order::withTrashed()->where('code_transaction', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Terapkan perubahan status order pada model + sinkronkan status unit instrumen terkait.
     * Tidak melakukan save() pada $order (dilakukan pemanggil).
     */
    private function applyStatusTransition(Order $order, string $status, ?string $returnedBy = null): void
    {
        $order->status = $status;
        $stockIds = $order->items()->pluck('instrument_stock_id');
        $meta = ['context' => 'order', 'reference' => $order->code];

        switch ($status) {
            case Order::STATUS_DIPINJAM:
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_DIPINJAM, $meta);
                break;

            case Order::STATUS_DIKEMBALIKAN:
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_TERSEDIA, $meta);
                $order->items()->update(['is_returned' => true]);
                $order->return_actual_date ??= now()->toDateString();
                $order->returned_by = $returnedBy ?? $order->returned_by;
                OrderEvent::record(OrderEvent::TYPE_DIKEMBALIKAN, $order, [
                    'note' => 'Seluruh unit dikembalikan'.($returnedBy ? " oleh {$returnedBy}" : ''),
                ]);
                break;

            case Order::STATUS_DIBATALKAN:
                // Kembalikan unit yang sempat dipinjam ke status tersedia.
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_TERSEDIA, $meta);
                // Rekam jejak pembatalan — terpisah dari hapus (deleted_at/by).
                // Order dibatalkan tetap tersimpan & tampil sebagai riwayat.
                $order->canceled_at ??= now();
                $order->canceled_by ??= auth()->user()?->name ?? 'system';
                OrderEvent::record(OrderEvent::TYPE_DIBATALKAN, $order, [
                    'note' => 'Order dibatalkan',
                ]);
                break;
        }
    }

    /**
     * Proses pengembalian per unit. Bila seluruh unit sudah kembali,
     * order otomatis menjadi "dikembalikan".
     */
    private function processReturns(Order $order, array $items, ?string $returnedBy = null): void
    {
        $anyReturned = false;

        foreach ($items as $data) {
            $item = $order->items()->find($data['id']);
            if (! $item) {
                continue;
            }

            $item->fill(array_intersect_key($data, array_flip(['is_returned', 'condition_in_id'])));
            $item->save();

            if (! empty($data['is_returned'])) {
                $anyReturned = true;
                InstrumentStock::transitionMany([$item->instrument_stock_id], InstrumentStock::STATUS_TERSEDIA, [
                    'context' => 'order',
                    'reference' => $order->code,
                ]);
            }
        }

        // Catat siapa yang mengembalikan begitu ada unit yang dikembalikan.
        if ($anyReturned && $returnedBy !== null) {
            $order->returned_by = $returnedBy;
            $order->save();
        }

        if ($order->items()->where('is_returned', false)->doesntExist()) {
            $order->status = Order::STATUS_DIKEMBALIKAN;
            $order->return_actual_date ??= now()->toDateString();
            $order->returned_by ??= $returnedBy;
            $order->save();
            OrderEvent::record(OrderEvent::TYPE_DIKEMBALIKAN, $order, [
                'note' => 'Seluruh unit dikembalikan'.($returnedBy ? " oleh {$returnedBy}" : ''),
            ]);
        }
    }
}
