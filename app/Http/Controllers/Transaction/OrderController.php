<?php

namespace App\Http\Controllers\Transaction;

use App\Events\OrderSubmitted;
use App\Http\Controllers\Controller;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderTransfer;
use App\Models\OrderWashing;
use App\Models\ProductionItem;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
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
            // Kecualikan batch PRODUKSI CSSD (internal, tanpa ruangan) — itu bukan
            // order peminjaman; tempatnya di pipeline Cleaning, bukan daftar ini.
            ->whereNotNull('room_id')
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
            // Identitas pasien (WAJIB) — no. rekam medis & nama pasien.
            'medical_record_no' => 'required|string|max:255',
            'patient_name' => 'required|string|max:255',
            'order_date' => 'required|date',
            // Jam pinjam WAJIB; hanya rencana kembali yang opsional.
            'order_time' => 'required|date_format:H:i',
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
                    'medical_record_no' => $validated['medical_record_no'],
                    'patient_name' => $validated['patient_name'],
                    'order_date' => $validated['order_date'],
                    'order_time' => $validated['order_time'],
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
                    'note' => 'Order peminjaman diajukan'
                        .($order->medical_record_no ? ' · RM '.$order->medical_record_no : '')
                        .($order->patient_name ? ' · '.$order->patient_name : ''),
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
        $pendingTransfers = OrderTransfer::with(['toRoom', 'requestedBy', 'items'])
            ->whereIn('from_order_id', $orders->pluck('id'))
            ->where('status', OrderTransfer::STATUS_PENDING)
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
            'medical_record_no' => $o->medical_record_no,
            'patient_name' => $o->patient_name,
            'room' => $o->room ? ['id' => $o->room->id, 'name' => $o->room->name] : null,
            'order_date' => $o->order_date,
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
                    'image_url' => $instrument->image_url,
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
                            .'terisi '.count($ids).'.'
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
     * Data tahap Packaging: kebutuhan unit per instrumen + jumlah yang sudah
     * di-generate + sisa unit tersedia. Untuk order pada status `pengemasan`.
     */
    public function packaging(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENGEMASAN) {
            return $this->error('Order ini tidak sedang dalam tahap packaging.', 422);
        }

        return $this->success('Data packaging berhasil diambil.', $this->packagingPayload($order));
    }

    /**
     * Generate unit fisik order secara OTOMATIS dari stok tersedia (tahap Packaging).
     * Bila stok kurang, ambil sebanyak yang ada — sisanya dibiarkan (tetap boleh
     * lanjut).
     *
     * Mode:
     * - preview=true  → hanya PRATINJAU unit yang akan dialokasikan (tidak disimpan,
     *   tidak membuat nomor batch). Dipakai tombol "Generate / Generate Ulang".
     * - preview=false → SIMPAN: buat order_item dari unit terpilih + bangkitkan
     *   nomor batch (code_transaction). Dipakai tombol "Simpan".
     */
    public function pack(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENGEMASAN) {
            return $this->error('Order ini tidak sedang dalam tahap packaging.', 422);
        }

        // Pratinjau: hitung unit yang akan dialokasikan tanpa menyentuh database.
        if ($request->boolean('preview')) {
            $proposed = $this->proposePacking($order);

            return $this->success('Pratinjau alokasi unit.', $this->packagingPayload($order, $proposed));
        }

        try {
            DB::transaction(function () use ($order) {
                $requirements = $this->buildRequirements($order);
                $order->load('items.instrumentStock');

                foreach ($requirements as $req) {
                    $remaining = $req['needed_qty'] - $this->generatedQtyFor($order, $req);
                    if ($remaining <= 0) {
                        continue;
                    }

                    $units = $this->availableUnitsFor($req, $remaining);
                    foreach ($units as $stock) {
                        $order->items()->create([
                            'instrument_stock_id' => $stock->id,
                            'source' => $req['source'],
                            'package_name' => $req['package_name'],
                            'condition_out_id' => $stock->condition_id,
                            'is_returned' => false,
                        ]);
                    }
                    // Muat ulang agar hitungan unit terbaru benar untuk requirement berikutnya.
                    $order->load('items.instrumentStock');
                }

                // Nomor batch / kode transaksi dibuat sekali saat simpan pertama.
                if (! $order->code_transaction) {
                    $order->code_transaction = $this->generateTransactionCode();
                    $order->save();

                    OrderEvent::where('order_id', $order->id)
                        ->whereNull('code_transaction')
                        ->update(['code_transaction' => $order->code_transaction]);
                }
            });

            return $this->success('Unit order berhasil disimpan.', $this->packagingPayload($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Inspection & Packaging — scan barcode satu unit instrumen di meja pengemasan.
     * Sistem mencocokkan unit ke daftar komponen set (checklist); bila cocok &
     * masih kurang, unit "dicentang" (jadi order_item). Petugas memindai satu per
     * satu untuk memverifikasi tiap komponen ada.
     */
    public function packScan(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENGEMASAN) {
            return $this->error('Order ini tidak sedang dalam tahap packaging.', 422);
        }

        $validated = $request->validate(['code' => 'required|string']);

        $stock = InstrumentStock::where('code', $validated['code'])->first();
        if (! $stock) {
            return $this->error("Unit dengan kode \"{$validated['code']}\" tidak ditemukan.", 404);
        }

        $requirements = $this->buildRequirements($order);
        $order->load('items.instrumentStock');

        // Sudah dicentang di order ini?
        if ($order->items->firstWhere('instrument_stock_id', $stock->id)) {
            return $this->error("Unit \"{$stock->code}\" sudah dicentang.", 422);
        }

        // Cari komponen set (requirement) untuk instrumen unit ini yang masih kurang.
        $req = collect($requirements)->first(fn ($r) => (int) $r['instrument_id'] === (int) $stock->instrument_id
            && $this->generatedQtyFor($order, $r) < $r['needed_qty']);

        if (! $req) {
            return $this->error(
                "Instrumen \"{$stock->instrument?->name}\" tidak ada di daftar set ini, atau jumlahnya sudah lengkap.",
                422
            );
        }

        if ($stock->status !== InstrumentStock::STATUS_TERSEDIA) {
            return $this->error("Unit \"{$stock->code}\" tidak tersedia (status: {$stock->status}).", 422);
        }

        if (OrderItem::where('is_returned', false)->where('instrument_stock_id', $stock->id)->exists()) {
            return $this->error("Unit \"{$stock->code}\" sudah dipakai order lain.", 422);
        }

        try {
            $order->items()->create([
                'instrument_stock_id' => $stock->id,
                'source' => $req['source'],
                'package_name' => $req['package_name'],
                'condition_out_id' => $stock->condition_id,
                'is_returned' => false,
            ]);

            return $this->success("Unit \"{$stock->code}\" tercentang.", $this->packagingPayload($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Inspection checklist — centang manual satu komponen set (tanpa scan barcode).
     * Mengalokasikan 1 unit tersedia untuk requirement (komponen) tertentu. Berguna
     * saat unit belum punya barcode tercetak / inspeksi manual.
     */
    public function packCheck(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENGEMASAN) {
            return $this->error('Order ini tidak sedang dalam tahap packaging.', 422);
        }

        $validated = $request->validate(['key' => 'required|string']);

        $requirements = $this->buildRequirements($order);
        $order->load('items.instrumentStock');

        $req = collect($requirements)->firstWhere('key', $validated['key']);
        if (! $req) {
            return $this->error('Komponen set tidak ditemukan.', 422);
        }

        if ($this->generatedQtyFor($order, $req) >= $req['needed_qty']) {
            return $this->error("Komponen \"{$req['instrument']['name']}\" sudah lengkap.", 422);
        }

        $unit = $this->availableUnitsFor($req, 1)->first();
        if (! $unit) {
            return $this->error("Stok \"{$req['instrument']['name']}\" tidak tersedia untuk dicentang.", 422);
        }

        try {
            $order->items()->create([
                'instrument_stock_id' => $unit->id,
                'source' => $req['source'],
                'package_name' => $req['package_name'],
                'condition_out_id' => $unit->condition_id,
                'is_returned' => false,
            ]);

            return $this->success("Komponen \"{$req['instrument']['name']}\" ({$unit->code}) tercentang.", $this->packagingPayload($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Batalkan centang satu unit yang sudah tersimpan (edit alokasi). Menghapus
     * order_item terkait → unit kembali bebas (tersedia untuk dipilih lagi). Hanya
     * boleh selama order masih tahap packaging (belum diselesaikan).
     */
    public function packUncheck(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENGEMASAN) {
            return $this->error('Order ini tidak sedang dalam tahap packaging.', 422);
        }

        $validated = $request->validate([
            'instrument_stock_id' => 'required|integer',
        ]);

        $item = $order->items()
            ->where('instrument_stock_id', $validated['instrument_stock_id'])
            ->where('is_returned', false)
            ->first();

        if (! $item) {
            return $this->error('Unit ini tidak tercentang pada order.', 422);
        }

        try {
            // Stok di packaging tidak dipindah statusnya (tetap tersedia); cukup
            // hapus order_item agar unit bebas dipakai lagi.
            $item->delete();

            return $this->success('Centang unit dibatalkan.', $this->packagingPayload($order));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Hitung pratinjau unit yang akan dialokasikan per requirement (tanpa menyimpan).
     * Mengembalikan map: key requirement → daftar [{id, code}] unit yang diusulkan.
     */
    private function proposePacking(Order $order): array
    {
        $requirements = $this->buildRequirements($order);
        $order->load('items.instrumentStock');

        // Unit yang sudah terpakai order lain — dikecualikan, dan ditambah unit yang
        // diusulkan di requirement sebelumnya agar tidak dipakai dua kali.
        $excludeIds = OrderItem::where('is_returned', false)->pluck('instrument_stock_id')->all();

        $proposed = [];
        foreach ($requirements as $req) {
            $remaining = $req['needed_qty'] - $this->generatedQtyFor($order, $req);
            if ($remaining <= 0) {
                $proposed[$req['key']] = [];

                continue;
            }

            $units = $this->availableUnitsFor($req, $remaining, $excludeIds);
            $proposed[$req['key']] = $units->map(fn ($u) => ['id' => $u->id, 'code' => $u->code])->all();
            foreach ($units as $u) {
                $excludeIds[] = $u->id;
            }
        }

        return $proposed;
    }

    /** Ambil unit tersedia untuk sebuah requirement (urut kode), kecuali yang sudah terpakai. */
    private function availableUnitsFor(array $req, int $limit, ?array $excludeIds = null)
    {
        $query = InstrumentStock::where('instrument_id', $req['instrument_id'])
            ->where('status', InstrumentStock::STATUS_TERSEDIA);

        if ($excludeIds === null) {
            $query->whereNotIn('id', OrderItem::where('is_returned', false)->select('instrument_stock_id'));
        } else {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->orderBy('code')->limit($limit)->get();
    }

    /**
     * Selesaikan tahap Packaging: order → `selesai` (siap disterilkan). Unit yang
     * sudah di-generate tetap berstatus `tersedia` agar bisa dimasukkan ke batch
     * sterilisasi di menu Sterilisasi.
     */
    public function packagingComplete(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENGEMASAN) {
            return $this->error('Order ini tidak sedang dalam tahap packaging.', 422);
        }

        $itemCount = $order->items()->where('is_returned', false)->count();
        if ($itemCount === 0) {
            return $this->error('Belum ada unit yang dicentang / di-generate untuk dikemas.', 422);
        }

        try {
            // Bangkitkan nomor batch bila belum ada (alur scan tidak lewat "Simpan").
            if (! $order->code_transaction) {
                $order->code_transaction = $this->generateTransactionCode();
                OrderEvent::where('order_id', $order->id)
                    ->whereNull('code_transaction')
                    ->update(['code_transaction' => $order->code_transaction]);
            }

            $order->status = Order::STATUS_SELESAI;
            $order->save();

            $order->load(self::DETAIL_RELATIONS);

            return $this->success('Order siap disterilkan.', $order);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Tahap Sterilisasi — daftar order pada pipeline sterilisasi:
     * - `selesai`     : siap dibuatkan batch sterilisasi.
     * - `sterilisasi` : batch sudah dibuat, menunggu validasi (Steril / Gagal).
     * Mengembalikan ringkasan unit fisik + info batch terbaru (bila ada).
     */
    public function readyToSterilize(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'items.instrumentStock.instrument',
            'sterilizations' => fn ($q) => $q->latest(),
        ])
            ->whereIn('status', [Order::STATUS_SELESAI, Order::STATUS_STERILISASI])
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('code_transaction', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->orderByDesc('processed_at')
            ->latest()
            ->paginate(20);

        $orders->getCollection()->transform(fn (Order $order) => $this->sterilizePayload($order));

        return $this->success('Data order pipeline sterilisasi berhasil diambil.', $orders);
    }

    /**
     * Validasi hasil sterilisasi sebuah order langsung dari tab Sterilization.
     * - result=selesai (Steril/Siap Rilis): batch → selesai, unit → tersedia (steril),
     *   order → `steril`. Order keluar dari pipeline sterilisasi.
     * - result=gagal (Gagal Steril/Wajib Re-proses): batch → gagal, unit → tersedia
     *   (dibebaskan), order kembali → `selesai` agar bisa dibuatkan batch ulang.
     */
    public function validateSterilization(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_STERILISASI) {
            return $this->error('Order ini tidak sedang dalam proses sterilisasi.', 422);
        }

        $validated = $request->validate([
            'result' => ['required', Rule::in([Sterilization::STATUS_SELESAI, Sterilization::STATUS_GAGAL])],
            'chemical_indicator' => 'nullable|string|max:100',
            'biological_indicator' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        $batch = $order->sterilizations()
            ->where('status', Sterilization::STATUS_DIPROSES)
            ->latest()
            ->first();

        if (! $batch) {
            return $this->error('Batch sterilisasi untuk order ini tidak ditemukan.', 422);
        }

        try {
            DB::transaction(function () use ($validated, $batch, $order) {
                $steril = $validated['result'] === Sterilization::STATUS_SELESAI;

                // Lengkapi hasil indikator / kedaluwarsa bila diisi saat validasi.
                $batch->fill(array_filter([
                    'chemical_indicator' => $validated['chemical_indicator'] ?? null,
                    'biological_indicator' => $validated['biological_indicator'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'note' => $validated['note'] ?? null,
                ], fn ($v) => $v !== null));
                $batch->status = $validated['result'];

                // Bila hasil steril & operator tak mengisi kedaluwarsa: set otomatis
                // = tgl sterilisasi + masa simpan default (agar gudang punya expiry).
                if ($steril && $batch->expiry_date === null) {
                    $base = $batch->sterilized_at ? $batch->sterilized_at->copy() : now();
                    $batch->expiry_date = $base->addDays(Sterilization::STERILE_SHELF_LIFE_DAYS)->toDateString();
                }

                $batch->save();

                // Unit kembali tersedia (steril & siap pakai, atau dibebaskan untuk re-proses).
                $stockIds = $batch->items()->pluck('instrument_stock_id');
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_TERSEDIA, [
                    'context' => 'sterilization',
                    'reference' => $batch->code,
                ]);

                if ($steril) {
                    $order->status = Order::STATUS_STERIL;
                    $order->save();
                    OrderEvent::record(OrderEvent::TYPE_STERIL, $order, [
                        'note' => 'Sterilisasi tervalidasi (steril & siap rilis) — batch '.$batch->code,
                    ]);
                } else {
                    // Gagal steril → wajib re-proses: kembali ke antrean siap-steril.
                    $order->status = Order::STATUS_SELESAI;
                    $order->save();
                    OrderEvent::record(OrderEvent::TYPE_GAGAL_STERIL, $order, [
                        'note' => 'Gagal steril, wajib re-proses — batch '.$batch->code,
                    ]);
                }
            });

            $order->load(['sterilizations' => fn ($q) => $q->latest()]);

            return $this->success(
                $validated['result'] === Sterilization::STATUS_SELESAI
                    ? 'Sterilisasi tervalidasi: alat steril & siap rilis.'
                    : 'Sterilisasi ditandai gagal: order kembali ke antrean siap-steril.',
                ['order_id' => $order->id, 'order_status' => $order->status]
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Buat batch sterilisasi langsung dari sebuah order yang siap (`selesai`).
     * Seluruh unit fisik order (order_item belum dikembalikan) dimasukkan ke batch,
     * unit → status `sterilisasi`, dan order → status `sterilisasi` (keluar dari
     * antrean siap-steril). Batch tercatat di modul Sterilisasi seperti biasa.
     */
    public function sterilize(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_SELESAI) {
            return $this->error('Order ini belum siap disterilkan (harus selesai packaging).', 422);
        }

        $validated = $request->validate([
            'machine' => 'required|string|max:255',
            'method' => ['nullable', Rule::in(Sterilization::METHODS)],
            'cycle_number' => 'nullable|string|max:100',
            'temperature' => 'nullable|numeric',
            'duration_minutes' => 'nullable|integer|min:0',
            'operator' => 'nullable|string|max:255',
            'sterilized_at' => 'required|date',
            'expiry_date' => 'nullable|date|after_or_equal:sterilized_at',
            'chemical_indicator' => 'nullable|string|max:100',
            'biological_indicator' => 'nullable|string|max:100',
            'note' => 'nullable|string',
        ]);

        $stockIds = $order->items()->where('is_returned', false)
            ->pluck('instrument_stock_id')->unique()->values();

        if ($stockIds->isEmpty()) {
            return $this->error('Order ini tidak punya unit untuk disterilkan.', 422);
        }

        try {
            $sterilization = DB::transaction(function () use ($validated, $stockIds, $order) {
                $sterilization = Sterilization::create([
                    ...collect($validated)->all(),
                    'order_id' => $order->id,
                    'method' => $validated['method'] ?? Sterilization::METHOD_UAP,
                    'status' => Sterilization::STATUS_DIPROSES,
                ]);

                foreach ($stockIds as $stockId) {
                    $sterilization->items()->create(['instrument_stock_id' => $stockId]);
                }

                // Unit fisik masuk batch → status sterilisasi.
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_STERILISASI, [
                    'context' => 'sterilization',
                    'reference' => $sterilization->code,
                ]);

                // Order keluar dari antrean siap-steril.
                $order->status = Order::STATUS_STERILISASI;
                $order->save();

                OrderEvent::record(OrderEvent::TYPE_DISTERILKAN, $order, [
                    'note' => 'Order dimasukkan ke batch sterilisasi '.$sterilization->code,
                ]);

                return $sterilization;
            });

            $sterilization->load('items.instrumentStock.instrument');

            return $this->success('Batch sterilisasi berhasil dibuat dari order.', [
                'order_id' => $order->id,
                'order_status' => $order->status,
                'sterilization' => $sterilization,
            ], 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Tahap 6 — Distribution & Tracking. Daftar order yang sudah di gudang steril
     * (status `digudang`) & siap didistribusikan ke unit pelayanan.
     */
    public function readyToDistribute(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'items.instrumentStock.instrument',
            'storages' => fn ($q) => $q->where('status', InstrumentStorage::STATUS_TERSIMPAN),
            'sterilizations' => fn ($q) => $q->where('status', 'selesai')->latest(),
        ])
            ->where('status', Order::STATUS_DIGUDANG)
            // Batch PRODUKSI CSSD (tanpa ruangan) = stok steril di gudang menunggu
            // diorder, bukan order yang siap didistribusikan. Kecualikan dari sini.
            ->whereNotNull('room_id')
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('code_transaction', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->orderByDesc('processed_at')
            ->latest()
            ->paginate(20);

        $orders->getCollection()->transform(fn (Order $order) => $this->distributePayload($order));

        return $this->success('Data order siap distribusi berhasil diambil.', $orders);
    }

    /**
     * Terima order masuk & SIAPKAN DISTRIBUSI. Karena order hanya meminta barang
     * yang sudah steril, order tidak perlu lewat pipeline Cleaning→Sterilisasi lagi:
     * sistem mengalokasikan unit steril dari gudang secara FEFO (first-expired-first-out),
     * lalu order → `digudang` (muncul di Distribution & Tracking).
     *
     * Reservasi: baris gudang unit yang dipilih dipindahkan kepemilikannya ke order
     * ini (order_id), sehingga (a) keluar dari pool "available sterile" milik produksi
     * (room_id null) dan (b) distribute menemukannya untuk dikeluarkan dari gudang.
     */
    public function acceptDistribution(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_DIAJUKAN) {
            return $this->error('Order ini sudah diproses dan tidak bisa diterima lagi.', 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $requirements = $this->buildRequirements($order);
                if (empty($requirements)) {
                    throw new \RuntimeException('Order tidak punya baris permintaan yang bisa dialokasikan.');
                }

                $today = now()->toDateString();
                $picked = [];        // stock id yang sudah dipilih (cegah dobel antar requirement)
                $allStockIds = [];

                foreach ($requirements as $req) {
                    // Kandidat unit steril (di gudang, belum kedaluwarsa, masih milik
                    // produksi) untuk instrumen ini, diurutkan FEFO (kedaluwarsa terdekat).
                    $rows = InstrumentStorage::withoutGlobalScopes()
                        ->join('instrument_stocks', 'instrument_stocks.id', '=', 'instrument_storages.instrument_stock_id')
                        // LEFT JOIN: stok pipeline produksi disimpan tanpa order (order_id null).
                        ->leftJoin('order', 'order.id', '=', 'instrument_storages.order_id')
                        ->whereNull('instrument_storages.deleted_by')
                        ->whereNull('instrument_stocks.deleted_by')
                        ->whereNull('order.deleted_by')
                        ->where('instrument_storages.status', InstrumentStorage::STATUS_TERSIMPAN)
                        ->where(fn ($w) => $w->whereNull('instrument_storages.expiry_date')
                            ->orWhereDate('instrument_storages.expiry_date', '>=', $today))
                        ->whereNull('order.room_id') // hanya stok produksi yang belum dialokasikan
                        ->where('instrument_stocks.instrument_id', $req['instrument_id'])
                        ->where('instrument_stocks.status', InstrumentStock::STATUS_TERSEDIA)
                        ->when($picked, fn ($q) => $q->whereNotIn('instrument_stocks.id', $picked))
                        ->orderByRaw('instrument_storages.expiry_date IS NULL, instrument_storages.expiry_date ASC')
                        ->get([
                            'instrument_storages.id as storage_id',
                            'instrument_stocks.id as stock_id',
                            'instrument_stocks.condition_id as condition_id',
                        ])
                        // Satu unit fisik bisa punya >1 baris gudang (mis. pernah
                        // diproduksi/disimpan berkali-kali) sehingga JOIN mengembalikan
                        // stock_id yang sama berulang. Dedup per unit — ambil baris FEFO
                        // paling awal — agar unit yang sama tidak dialokasikan dua kali.
                        ->unique('stock_id')
                        ->take($req['needed_qty'])
                        ->values();

                    if ($rows->count() < $req['needed_qty']) {
                        throw new \RuntimeException(
                            "Stok steril \"{$req['instrument']['name']}\" tidak cukup: butuh {$req['needed_qty']}, tersedia ".$rows->count().'.'
                        );
                    }

                    foreach ($rows as $row) {
                        $picked[] = $row->stock_id;
                        $allStockIds[] = $row->stock_id;

                        $order->items()->create([
                            'instrument_stock_id' => $row->stock_id,
                            'source' => $req['source'],
                            'package_name' => $req['package_name'],
                            'condition_out_id' => $row->condition_id,
                            'is_returned' => false,
                        ]);

                        // Pindahkan kepemilikan baris gudang ke order ini (reservasi +
                        // agar distribute mengeluarkannya dari gudang).
                        InstrumentStorage::withoutGlobalScopes()
                            ->where('id', $row->storage_id)
                            ->update(['order_id' => $order->id, 'updated_by' => auth()->user()?->name]);
                    }
                }

                $order->status = Order::STATUS_DIGUDANG;
                $order->processed_at = now();
                $order->processed_by = auth()->user()?->name;
                if (! $order->code_transaction) {
                    $order->code_transaction = $this->generateTransactionCode();
                }
                $order->save();

                OrderEvent::record(OrderEvent::TYPE_DITERIMA, $order, [
                    'note' => 'Order diterima — unit steril dialokasikan (FEFO) dari gudang, siap distribusi',
                ]);
            });

            $order->load(self::DETAIL_RELATIONS);

            return $this->success('Order diterima & siap didistribusikan.', $order);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Distribusikan order steril ke unit pelayanan (Double Verification).
     * Body: `recipient` (ruangan/petugas penerima hasil scan). No RM & Nama Pasien
     * tidak lagi diinput di sini — diisi saat pembuatan order (tautan RM pasien,
     * full traceability loop) dan dibawa apa adanya ke event distribusi.
     *
     * Efek: unit keluar gudang (storage `keluar`), unit → `dipinjam`, order →
     * `dipinjam` (Terdistribusi/Digunakan), event `terdistribusi`.
     */
    public function distribute(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_DIGUDANG) {
            return $this->error('Order ini belum berada di gudang steril / tidak siap didistribusikan.', 422);
        }

        $validated = $request->validate([
            'recipient' => 'required|string|max:255',
            'note' => 'nullable|string',
        ]);

        $stockIds = $order->items()->where('is_returned', false)
            ->pluck('instrument_stock_id')->unique()->values();

        if ($stockIds->isEmpty()) {
            return $this->error('Order ini tidak punya unit untuk didistribusikan.', 422);
        }

        try {
            DB::transaction(function () use ($validated, $order, $stockIds) {
                // Keluarkan unit dari gudang steril.
                InstrumentStorage::where('order_id', $order->id)
                    ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
                    ->update([
                        'status' => InstrumentStorage::STATUS_KELUAR,
                        'removed_at' => now(),
                        'updated_by' => auth()->user()?->name,
                    ]);

                // Unit → dipinjam (Terdistribusi/Digunakan).
                InstrumentStock::transitionMany($stockIds, InstrumentStock::STATUS_DIPINJAM, [
                    'context' => 'order',
                    'reference' => $order->code,
                ]);

                $order->status = Order::STATUS_DIPINJAM;
                $order->distributed_to = $validated['recipient'];
                $order->distributed_at = now();
                $order->save();

                // No RM & Nama Pasien diisi saat pembuatan order (bukan di distribusi).
                $rm = $order->medical_record_no;
                $patient = $order->patient_name;
                OrderEvent::record(OrderEvent::TYPE_TERDISTRIBUSI, $order, [
                    'note' => 'Diterima '.$validated['recipient']
                        .($rm ? ' · RM '.$rm : '')
                        .($patient ? ' · '.$patient : ''),
                ]);
            });

            $order->load(self::DETAIL_RELATIONS);

            return $this->success('Alat steril berhasil didistribusikan.', $order);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /** Ringkasan order siap-distribusi + unit & lokasi raknya. */
    private function distributePayload(Order $order): array
    {
        // Dedupe per unit fisik: satu instrument_stock tak boleh muncul (dan
        // diambil dari gudang) dua kali walau ada order_item ganda.
        $units = $order->items->where('is_returned', false)->unique('instrument_stock_id')->values();
        $rackByStock = $order->relationLoaded('storages')
            ? $order->storages->keyBy('instrument_stock_id')
            : collect();
        $expiry = $order->relationLoaded('sterilizations')
            ? optional($order->sterilizations->first())->expiry_date
            : null;
        // Fallback: tgl kedaluwarsa dari unit steril di gudang (paling awal) bila
        // batch sterilisasi order tidak menyimpan expiry.
        if ($expiry === null && $order->relationLoaded('storages')) {
            $expiry = $order->storages->pluck('expiry_date')->filter()->sort()->first();
        }

        return [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name, 'code' => $order->room->code] : null,
            'processed_at' => $order->processed_at,
            'expiry_date' => $expiry,
            'unit_count' => $units->count(),
            'units' => $units->map(fn ($it) => [
                'id' => $it->instrument_stock_id,
                'code' => $it->instrumentStock?->code,
                'instrument' => $it->instrumentStock?->instrument?->name,
                'rack_code' => $rackByStock->get($it->instrument_stock_id)?->rack_code,
            ])->values(),
        ];
    }

    /** Ringkasan order pipeline sterilisasi + unit fisik + batch terbaru. */
    private function sterilizePayload(Order $order): array
    {
        $units = $order->items->where('is_returned', false)->values();

        // Batch terbaru order ini (untuk order berstatus sterilisasi = menunggu validasi).
        $batch = $order->relationLoaded('sterilizations') ? $order->sterilizations->first() : null;

        return [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            'order_date' => $order->order_date,
            'processed_at' => $order->processed_at,
            'unit_count' => $units->count(),
            'units' => $units->map(fn ($it) => [
                'id' => $it->instrument_stock_id,
                'code' => $it->instrumentStock?->code,
                'instrument' => $it->instrumentStock?->instrument?->name,
                'source' => $it->source,
                'package_name' => $it->package_name,
            ])->values(),
            'sterilization' => $batch ? [
                'id' => $batch->id,
                'code' => $batch->code,
                'machine' => $batch->machine,
                'method' => $batch->method,
                'cycle_number' => $batch->cycle_number,
                'temperature' => $batch->temperature,
                'duration_minutes' => $batch->duration_minutes,
                'sterilized_at' => $batch->sterilized_at,
                'expiry_date' => $batch->expiry_date,
                'chemical_indicator' => $batch->chemical_indicator,
                'biological_indicator' => $batch->biological_indicator,
                'status' => $batch->status,
            ] : null,
        ];
    }

    /** Unit (order_item) yang sudah di-generate untuk sebuah requirement (instrumen+asal+paket). */
    private function generatedItemsFor(Order $order, array $req)
    {
        return $order->items
            ->filter(fn ($it) => $it->source === $req['source']
                && (string) $it->package_name === (string) $req['package_name']
                && (int) ($it->instrumentStock?->instrument_id ?? 0) === (int) $req['instrument_id'])
            ->values();
    }

    /** Jumlah unit yang sudah di-generate untuk sebuah requirement. */
    private function generatedQtyFor(Order $order, array $req): int
    {
        return $this->generatedItemsFor($order, $req)->count();
    }

    /**
     * Susun payload tahap packaging: order + requirement + status generate per
     * instrumen. `$proposedByKey` (opsional) berisi unit pratinjau yang BELUM
     * disimpan, digabung ke daftar unit agar tampil di pratinjau.
     */
    private function packagingPayload(Order $order, array $proposedByKey = []): array
    {
        $requirements = $this->buildRequirements($order);
        $order->load(['items.instrumentStock', 'room']);

        $boundIds = OrderItem::where('is_returned', false)->pluck('instrument_stock_id')->all();

        $reqs = collect($requirements)->values()->map(function ($req) use ($order, $boundIds, $proposedByKey) {
            // Unit (stock) yang tersedia untuk dicentang — beserta kodenya, agar
            // frontend bisa menampilkan kode unit yang siap dipilih (bukan tombol generik).
            $availableUnits = InstrumentStock::where('instrument_id', $req['instrument_id'])
                ->where('status', InstrumentStock::STATUS_TERSEDIA)
                ->whereNotIn('id', $boundIds)
                ->orderBy('code')
                ->get(['id', 'code'])
                ->map(fn ($u) => ['id' => $u->id, 'code' => $u->code])
                ->values();

            // Kode unit (stock) yang sudah di-generate (tersimpan) untuk requirement ini.
            $generatedUnits = $this->generatedItemsFor($order, $req)
                ->map(fn ($it) => [
                    'id' => $it->instrument_stock_id,
                    'code' => $it->instrumentStock?->code,
                ])
                ->values();

            // Gabungkan unit pratinjau (belum disimpan) bila ada.
            $units = $generatedUnits->concat($proposedByKey[$req['key']] ?? [])->values();

            return [
                'key' => $req['key'],
                'instrument' => $req['instrument'],
                'source' => $req['source'],
                'package_name' => $req['package_name'],
                'needed_qty' => $req['needed_qty'],
                'generated_qty' => $units->count(),
                'generated_units' => $units,
                'available_count' => $availableUnits->count(),
                'available_units' => $availableUnits,
            ];
        });

        return [
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'code_transaction' => $order->code_transaction,
                'status' => $order->status,
                'borrowed_by' => $order->borrowed_by,
                'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            ],
            'requirements' => $reqs,
        ];
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

        $orderEvents = OrderEvent::with('room')
            ->whereIn('order_id', $orderIds)
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

        // Riwayat pipeline CSSD (Produksi → Cleaning → Sterilisasi → Simpan Rak)
        // yang menghasilkan unit yang dipinjam — ditelusuri dari KODE PRODUKSI tiap
        // unit, dibaca langsung dari tabel tiap tahap agar semuanya terekam.
        $stockIds = OrderItem::whereIn('order_id', $orderIds)
            ->pluck('instrument_stock_id')->filter()->unique();
        $pipeline = $this->pipelineTimeline($stockIds);

        // Gabung & urut kronologis (produksi/steril/simpan terjadi sebelum dipinjam).
        $events = $orderEvents->concat($pipeline)
            ->sortBy(fn ($e) => optional($e['created_at'])->timestamp ?? 0)
            ->values();

        $order->setAttribute('timeline', $events);
    }

    /**
     * Rangkai riwayat pipeline CSSD untuk sekumpulan unit (instrument_stock_id):
     * Produksi (batch terbaru tiap unit) → Cleaning (via production_code) →
     * Sterilisasi (batch terbaru tiap unit) → Simpan di Rak (instrument_storages).
     * Setiap tahap dibaca dari tabelnya langsung agar dijamin ter-record.
     *
     * @param  \Illuminate\Support\Collection<int,int>  $stockIds
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function pipelineTimeline($stockIds): \Illuminate\Support\Collection
    {
        $stockIds = collect($stockIds)->filter()->unique()->values();
        if ($stockIds->isEmpty()) {
            return collect();
        }

        $events = collect();
        $seq = 0;
        $push = function (string $type, $at, ?string $actor, ?string $note) use (&$events, &$seq) {
            $events->push([
                // Id sintetis negatif → unik & tidak bentrok dengan id OrderEvent.
                'id' => -(++$seq),
                'type' => $type,
                'room' => null,
                'actor' => $actor,
                'borrowed_by' => null,
                'note' => $note,
                'created_at' => $at,
            ]);
        };

        // 1. Produksi — batch produksi TERBARU tiap unit (siklus aktif), dedup per batch.
        $prodItemIds = ProductionItem::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $stockIds)
            ->groupBy('instrument_stock_id')
            ->pluck('id');
        $productions = ProductionItem::with('production')
            ->whereIn('id', $prodItemIds)
            ->get()
            ->pluck('production')->filter()->unique('id');

        foreach ($productions as $p) {
            $push('produksi', $p->completed_at ?? $p->created_at, $p->completed_by ?? $p->created_by, "Batch produksi {$p->code}");
        }

        // 2. Cleaning — tahap washing yang mengalir dari batch produksi (via production_code).
        $washings = OrderWashing::whereIn('production_code', $productions->pluck('code'))->get();
        foreach ($washings as $w) {
            $type = match ($w->status) {
                OrderWashing::STATUS_SELESAI => 'selesai_cuci',
                OrderWashing::STATUS_GAGAL => 'gagal_cuci',
                default => 'diproses',
            };
            $push($type, $w->completed_at ?? $w->started_at ?? $w->created_at, $w->completed_by ?? $w->started_by, "Cleaning {$w->code}");
        }

        // 3. Sterilisasi — batch steril TERBARU tiap unit, dedup per batch.
        $sterItemIds = SterilizationItem::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $stockIds)
            ->groupBy('instrument_stock_id')
            ->pluck('id');
        $sters = SterilizationItem::with('sterilization')
            ->whereIn('id', $sterItemIds)
            ->get()
            ->pluck('sterilization')->filter()->unique('id');

        foreach ($sters as $s) {
            $type = match ($s->status) {
                Sterilization::STATUS_SELESAI => 'steril',
                Sterilization::STATUS_GAGAL => 'gagal_steril',
                default => 'disterilkan',
            };
            $push($type, $s->completed_at ?? $s->sterilized_at ?? $s->created_at, $s->completed_by ?? $s->created_by, "Sterilisasi {$s->code}");
        }

        // 4. Simpan di Rak — penempatan TERBARU tiap unit di gudang steril (siklus
        // aktif), dikelompokkan per rak.
        $storageIds = InstrumentStorage::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $stockIds)
            ->groupBy('instrument_stock_id')
            ->pluck('id');
        $storages = InstrumentStorage::whereIn('id', $storageIds)->get();
        foreach ($storages->groupBy('rack_code') as $rack => $rows) {
            $first = $rows->sortBy('stored_at')->first();
            $push('disimpan', $first->stored_at ?? $first->created_at, $first->created_by, "Disimpan di rak {$rack} ({$rows->count()} unit)");
        }

        return $events;
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
