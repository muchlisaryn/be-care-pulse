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
use App\Models\Packaging;
use App\Models\PackagingItem;
use App\Models\Production;
use App\Models\ProductionItem;
use App\Models\Room;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
                // Cari berdasarkan: kode order, nama peminjam, no. RM pasien,
                // nama pasien, dan nama ruangan.
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhere('medical_record_no', 'like', "%{$s}%")
                    ->orWhere('patient_name', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($q) => $q->where('name', 'like', "%{$s}%")))
            )
            // Filter rentang tanggal pinjam (order_date), inklusif.
            ->when($request->date_from, fn ($q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->whereDate('order_date', '<=', $d))
            ->latest()
            ->paginate(20);

        return $this->success('Data peminjaman berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        // Layanan ruangan menentukan wajib/tidaknya identitas pasien:
        // hanya RAWAT INAP yang mewajibkan No. RM & Nama Pasien; rawat jalan / IGD
        // boleh kosong.
        $room = Room::find($request->input('room_id'));
        $patientRequired = $room && $room->layanan === 'rawat_inap';

        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'borrowed_by' => 'nullable|string|max:255',
            // Identitas pasien wajib HANYA untuk layanan rawat inap.
            'medical_record_no' => ($patientRequired ? 'required' : 'nullable').'|string|max:255',
            'patient_name' => ($patientRequired ? 'required' : 'nullable').'|string|max:255',
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
                    'medical_record_no' => $validated['medical_record_no'] ?? null,
                    'patient_name' => $validated['patient_name'] ?? null,
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
        // Detail Order Instrumen: timeline cukup siklus order (Dibuat → Diterima CSSD →
        // Terdistribusi → Dikembalikan), tanpa pipeline produksi/cleaning/steril.
        $this->attachTimeline($order, includePipeline: false);

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
                // = base + batas steril mesin washer (master), fallback default.
                if ($steril && $batch->expiry_date === null) {
                    $batch->expiry_date = $batch->computeExpiryDate();
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
     * Pilihan barang yang bisa didistribusikan untuk sebuah order (dipakai modal
     * "Distribusikan"). Satu entri per BARIS PERMINTAAN order, dipilih sesuai
     * bentuknya:
     * - `satuan` → petugas memilih unit satu per satu (opsi = unit steril di gudang).
     * - `paket`  → petugas memilih PER PAKET utuh (opsi = satu set lengkap dari satu
     *   batch produksi); memilih 1 opsi otomatis mengambil semua unit isi paket itu.
     *
     * Kandidat = unit yang sudah direservasi untuk order ini (alokasi FEFO saat order
     * diterima) + unit steril milik produksi yang masih bebas. Urut FEFO.
     */
    public function distributionOptions(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_DIGUDANG) {
            return $this->error('Order ini belum berada di gudang steril / tidak siap didistribusikan.', 422);
        }

        $order->loadMissing(['requestItems.instrument', 'requestItems.catalog.items.instrument']);

        $reserved = $order->items()->where('is_returned', false)
            ->pluck('instrument_stock_id')->map(fn ($id) => (int) $id)->unique()->all();

        $lines = [];
        $taken = [];   // stock yang sudah ditawarkan di baris sebelumnya (cegah dobel pilih)

        foreach ($order->requestItems as $line) {
            $lines[] = $line->type === 'paket'
                ? $this->packageLineOptions($order, $line, $reserved, $taken)
                : $this->unitLineOptions($order, $line, $reserved, $taken);
        }

        return $this->success('Pilihan unit distribusi berhasil diambil.', [
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'code_transaction' => $order->code_transaction,
                'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
                'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            ],
            'requirements' => $lines,
        ]);
    }

    /**
     * Baris permintaan SATUAN: petugas memilih unit satu per satu. Satu opsi = satu
     * unit steril di gudang (ditandai kode produksinya).
     */
    private function unitLineOptions(Order $order, $line, array $reserved, array &$taken): array
    {
        $instrument = $line->instrument;
        $needed = (int) $line->quantity;

        $rows = $instrument
            ? $this->distributionCandidates($order, [
                'instrument_id' => $instrument->id,
                'source' => 'satuan',
                'package_name' => null,
            ])->reject(fn ($r) => in_array((int) $r->stock_id, $taken, true))->values()
            : collect();

        $codes = $this->productionCodeMap($rows->pluck('stock_id')->all());

        foreach ($rows as $r) {
            $taken[] = (int) $r->stock_id;
        }

        $options = $rows->map(fn ($r) => [
            // Nilai yang dikirim balik saat distribusi (satu opsi = satu unit).
            'value' => 'u'.(int) $r->stock_id,
            'production_code' => $codes[(int) $r->stock_id] ?? null,
            'name' => $instrument?->name,
            'stock_ids' => [(int) $r->stock_id],
            'expiry_date' => $r->expiry_date,
            'rack_code' => $r->rack_code,
        ])->values();

        return [
            'key' => 'line-'.$line->id,
            'kind' => 'satuan',
            'name' => $instrument?->name,
            'needed_qty' => $needed,
            // Satuan dihitung per unit.
            'unit_label' => 'unit',
            'options' => $options,
            'selected' => $this->preselect($options, $reserved, $needed),
        ];
    }

    /**
     * Baris permintaan PAKET: petugas memilih PER PAKET, bukan per instrumen. Satu opsi
     * = satu set lengkap (isi katalog) yang berasal dari SATU batch produksi, sehingga
     * paket yang dikeluarkan tidak tercampur antar batch. Satu batch yang memproduksi
     * beberapa set dengan nama paket sama menghasilkan beberapa opsi (Set 1, Set 2, …).
     */
    private function packageLineOptions(Order $order, $line, array $reserved, array &$taken): array
    {
        $packageName = $line->package_name ?? $line->catalog?->name ?? 'Paket';
        $needed = (int) $line->quantity;   // jumlah SET yang diminta

        // Isi paket: instrumen apa saja & berapa unit per set.
        $contents = collect($line->catalog?->items ?? [])
            ->filter(fn ($ci) => $ci->instrument && $ci->quantity > 0)
            ->values();

        // Kandidat unit per instrumen isi paket, dikelompokkan per batch produksi.
        $byCodeInstrument = [];   // [production_code][instrument_id] => stock_id[] (FEFO)
        $rackByStock = [];        // lokasi rak tiap unit (untuk label opsi)
        $expiryByStock = [];
        foreach ($contents as $ci) {
            $rows = $this->distributionCandidates($order, [
                'instrument_id' => $ci->instrument->id,
                'source' => 'paket',
                'package_name' => $packageName,
            ])->reject(fn ($r) => in_array((int) $r->stock_id, $taken, true))->values();

            $codes = $this->productionCodeMap($rows->pluck('stock_id')->all());

            foreach ($rows as $r) {
                $stockId = (int) $r->stock_id;
                $taken[] = $stockId;
                $code = $codes[$stockId] ?? null;
                if ($code === null) {
                    // Tanpa kode produksi paket tak bisa dijamin satu batch — lewati.
                    continue;
                }
                $rackByStock[$stockId] = $r->rack_code;
                $expiryByStock[$stockId] = $r->expiry_date;
                $byCodeInstrument[$code][$ci->instrument->id][] = $stockId;
            }
        }

        // Rakit set utuh per batch: satu set = quantity unit untuk tiap instrumen isi paket.
        $options = collect();
        foreach ($byCodeInstrument as $code => $perInstrument) {
            $sets = $contents
                ->map(fn ($ci) => intdiv(count($perInstrument[$ci->instrument->id] ?? []), (int) $ci->quantity))
                ->min() ?? 0;

            for ($i = 0; $i < $sets; $i++) {
                $stockIds = [];
                foreach ($contents as $ci) {
                    $take = (int) $ci->quantity;
                    $stockIds = array_merge(
                        $stockIds,
                        array_slice($perInstrument[$ci->instrument->id], $i * $take, $take)
                    );
                }

                // Rak & kedaluwarsa paket diwakili unit isinya (satu paket disimpan
                // di satu rak; ambil rak pertama yang terisi, kedaluwarsa terdekat).
                $racks = collect($stockIds)->map(fn ($id) => $rackByStock[$id] ?? null)->filter()->unique();
                $expiry = collect($stockIds)->map(fn ($id) => $expiryByStock[$id] ?? null)->filter()->sort()->first();

                $options->push([
                    'value' => 'p'.$code.'#'.$i,
                    'production_code' => $code,
                    'name' => $packageName,
                    'stock_ids' => $stockIds,
                    // Set ke-berapa dari batch yang sama (batch bisa memproduksi >1 set).
                    'set_index' => $sets > 1 ? $i + 1 : null,
                    'expiry_date' => $expiry,
                    'rack_code' => $racks->implode(', ') ?: null,
                ]);
            }
        }

        $options = $options->values();

        return [
            'key' => 'line-'.$line->id,
            'kind' => 'paket',
            'name' => $packageName,
            'needed_qty' => $needed,
            // Paket dihitung per set, bukan per unit.
            'unit_label' => 'paket',
            'options' => $options,
            'selected' => $this->preselect($options, $reserved, $needed),
        ];
    }

    /**
     * Pilihan default modal: dahulukan opsi yang unitnya sudah direservasi untuk order
     * ini (hasil alokasi FEFO saat order diterima), lalu lengkapi dengan opsi FEFO
     * teratas sampai jumlah yang diminta terpenuhi.
     */
    private function preselect($options, array $reserved, int $needed): array
    {
        $picked = [];

        foreach ($options as $opt) {
            if (count($picked) >= $needed) {
                break;
            }
            // Opsi dianggap "sudah dialokasikan" bila SELURUH unitnya direservasi order ini.
            if (! empty(array_diff($opt['stock_ids'], $reserved))) {
                continue;
            }
            $picked[] = $opt['value'];
        }

        foreach ($options as $opt) {
            if (count($picked) >= $needed) {
                break;
            }
            if (! in_array($opt['value'], $picked, true)) {
                $picked[] = $opt['value'];
            }
        }

        return $picked;
    }

    /**
     * Kandidat unit steril di gudang untuk satu requirement order distribusi:
     * baris gudang berstatus `tersimpan`, belum kedaluwarsa, bentuk simpannya cocok
     * (satuan/paket bernama sama), dan pemiliknya order ini (sudah direservasi) atau
     * masih pool produksi (belum dialokasikan ke order manapun). Urut FEFO.
     */
    private function distributionCandidates(Order $order, array $req)
    {
        $today = now()->toDateString();

        return InstrumentStorage::withoutGlobalScopes()
            ->join('instrument_stocks', 'instrument_stocks.id', '=', 'instrument_storages.instrument_stock_id')
            ->leftJoin('order', 'order.id', '=', 'instrument_storages.order_id')
            ->whereNull('instrument_storages.deleted_by')
            ->whereNull('instrument_stocks.deleted_by')
            ->whereNull('order.deleted_by')
            ->where('instrument_storages.status', InstrumentStorage::STATUS_TERSIMPAN)
            ->where(fn ($w) => $w->whereNull('instrument_storages.expiry_date')
                ->orWhereDate('instrument_storages.expiry_date', '>=', $today))
            ->where(fn ($w) => $w->where('instrument_storages.order_id', $order->id)
                ->orWhereNull('order.room_id'))
            ->where('instrument_stocks.instrument_id', $req['instrument_id'])
            ->where('instrument_stocks.status', InstrumentStock::STATUS_TERSEDIA)
            ->where('instrument_storages.source', $req['source'])
            ->when(
                $req['source'] === 'paket',
                fn ($q) => $q->where('instrument_storages.package_name', $req['package_name'])
            )
            ->orderByRaw('instrument_storages.expiry_date IS NULL, instrument_storages.expiry_date ASC')
            ->get([
                'instrument_storages.id as storage_id',
                'instrument_storages.expiry_date as expiry_date',
                'instrument_storages.rack_code as rack_code',
                'instrument_stocks.id as stock_id',
                'instrument_stocks.code as unit_code',
                'instrument_stocks.condition_id as condition_id',
            ])
            // Satu unit fisik bisa punya >1 baris gudang; ambil baris FEFO paling awal.
            ->unique('stock_id')
            ->values();
    }

    /** Kode batch produksi (PRD-...) terakhir tiap unit, dipakai sebagai label bungkus steril. */
    private function productionCodeMap(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return ProductionItem::with('production')
            ->whereIn('instrument_stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            // Urut id ASC → batch terbaru menimpa yang lama.
            ->mapWithKeys(fn ($it) => [(int) $it->instrument_stock_id => $it->production?->code])
            ->all();
    }

    /**
     * Peta instrument_stock_id → NAMA instrumen dari SNAPSHOT production_item
     * (production_item.name), bukan relasi master. Batch terbaru (id ASC) menimpa.
     */
    private function productionNameMap(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return ProductionItem::whereIn('instrument_stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($it) => [(int) $it->instrument_stock_id => $it->name])
            ->all();
    }

    /**
     * Terapkan pilihan unit petugas saat distribusi: unit terpilih direservasi ke
     * order ini, unit yang tadinya dialokasikan (FEFO otomatis) tapi tidak jadi
     * dipilih dikembalikan ke pool produksi, lalu baris order_item ditulis ulang
     * agar cocok dengan yang benar-benar dikeluarkan dari gudang.
     */
    private function reallocateDistribution(Order $order, array $stockIds): void
    {
        $wanted = collect($stockIds)->map(fn ($id) => (int) $id)->unique();
        $requirements = $this->buildRequirements($order);

        $chosen = collect();  // baris kandidat terpilih (storage_id, stock_id, condition_id)
        $taken = [];

        foreach ($requirements as $req) {
            $rows = $this->distributionCandidates($order, $req)
                ->reject(fn ($r) => in_array((int) $r->stock_id, $taken, true))
                ->values();

            $pick = $rows->filter(fn ($r) => $wanted->contains((int) $r->stock_id))
                ->map(fn ($r) => [
                    'storage_id' => (int) $r->storage_id,
                    'stock_id' => (int) $r->stock_id,
                    'condition_id' => $r->condition_id,
                    'source' => $req['source'],
                    'package_name' => $req['package_name'],
                ])
                ->values();

            if ($pick->count() !== (int) $req['needed_qty']) {
                $bentuk = $req['source'] === 'paket'
                    ? " (paket \"{$req['package_name']}\")"
                    : ' (satuan)';
                throw new \RuntimeException(
                    "Pilihan unit \"{$req['instrument']['name']}\"{$bentuk} harus {$req['needed_qty']} unit, terpilih ".$pick->count().'.'
                );
            }

            foreach ($rows as $r) {
                $taken[] = (int) $r->stock_id;
            }

            $chosen = $chosen->concat($pick);
        }

        $unknown = $wanted->diff($chosen->pluck('stock_id'));
        if ($unknown->isNotEmpty()) {
            throw new \RuntimeException('Ada unit terpilih yang tidak tersedia lagi di gudang steril. Muat ulang daftar unit.');
        }

        $chosenStockIds = $chosen->pluck('stock_id')->all();
        $actor = auth()->user()?->name;

        // Lepas reservasi unit lama yang tidak jadi dipilih → kembali ke pool produksi.
        InstrumentStorage::withoutGlobalScopes()
            ->whereNull('deleted_by')
            ->where('order_id', $order->id)
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->whereNotIn('instrument_stock_id', $chosenStockIds)
            ->update(['order_id' => null, 'updated_by' => $actor]);

        // Reservasi unit terpilih ke order ini (agar distribute mengeluarkannya).
        InstrumentStorage::withoutGlobalScopes()
            ->whereIn('id', $chosen->pluck('storage_id')->all())
            ->update(['order_id' => $order->id, 'updated_by' => $actor]);

        // Tulis ulang unit order agar sama persis dengan pilihan petugas.
        $order->items()->where('is_returned', false)->delete();
        foreach ($chosen as $row) {
            $order->items()->create([
                'instrument_stock_id' => $row['stock_id'],
                'source' => $row['source'],
                'package_name' => $row['package_name'],
                'condition_out_id' => $row['condition_id'],
                'is_returned' => false,
            ]);
        }
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
                    // Bentuk simpan harus cocok: permintaan satuan hanya boleh mengambil
                    // unit yang disimpan satuan, permintaan paket hanya dari unit yang
                    // disimpan sebagai paket bernama sama (produksi menentukan bentuknya).
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
                        ->where('instrument_storages.source', $req['source'])
                        ->when(
                            $req['source'] === 'paket',
                            fn ($q) => $q->where('instrument_storages.package_name', $req['package_name'])
                        )
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
                        $bentuk = $req['source'] === 'paket'
                            ? " (paket \"{$req['package_name']}\")"
                            : ' (satuan)';
                        throw new \RuntimeException(
                            "Stok steril \"{$req['instrument']['name']}\"{$bentuk} tidak cukup: butuh {$req['needed_qty']}, tersedia ".$rows->count().'.'
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
     * Body: `recipient` (ruangan/petugas penerima hasil scan) + `stock_ids` (opsional,
     * unit yang dipilih petugas di modal — lihat `distributionOptions`; bila kosong,
     * dipakai alokasi FEFO otomatis dari saat order diterima). No RM & Nama Pasien
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
            'stock_ids' => 'nullable|array',
            'stock_ids.*' => 'integer',
        ]);

        try {
            DB::transaction(function () use ($validated, $order) {
                // Petugas memilih sendiri stok yang dikeluarkan (kode produksi mana) →
                // sesuaikan reservasi gudang & unit order sebelum dikeluarkan.
                if (! empty($validated['stock_ids'])) {
                    $this->reallocateDistribution($order, $validated['stock_ids']);
                }

                $stockIds = $order->items()->where('is_returned', false)
                    ->pluck('instrument_stock_id')->unique()->values();

                if ($stockIds->isEmpty()) {
                    throw new \RuntimeException('Order ini tidak punya unit untuk didistribusikan.');
                }

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
        } catch (\RuntimeException $e) {
            // Pilihan unit tidak valid / stok berubah — validasi bisnis, bukan error server.
            return $this->error($e->getMessage(), 422);
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
     * - Kode order (ORD-NNN) / no. transaksi → tampilkan order tersebut.
     * - Kode unit alat (mis. KLL-002) → cari order TERAKHIR yang memuat unit itu.
     * - Kode batch PRODUKSI (PRD-yymmddNN) → label pada bungkus steril; cari order
     *   TERAKHIR yang memuat unit dari batch produksi itu.
     * - Kode produksi pada LABEL steril = kode batch + id production_item digabung
     *   (mis. PRD260714031 = batch PRD26071403 + item 1) → cari order TERAKHIR yang
     *   memuat unit label tersebut (untuk paket: seluruh unit paket yang sama).
     * Dipakai halaman Scan & Tracking + modal Pengembalian; QR unit yang sudah
     * tercetak tetap bisa dipakai.
     */
    /**
     * Tracking (timeline) satu order — di-LAZY-LOAD terpisah dari detailnya (mis.
     * saat modal Pengembalian Instrumen dibuka), supaya payload scan/detail ringan
     * dan riwayat pipeline (produksi → cleaning → packaging → steril) hanya dihitung
     * ketika benar-benar ditampilkan.
     */
    public function timeline(Order $order): JsonResponse
    {
        $this->attachTimeline($order);

        return $this->success('Tracking order berhasil diambil.', [
            'timeline' => $order->getAttribute('timeline'),
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $code = $validated['code'];

        // Unit yang benar-benar diwakili kode yang dipindai (kode unit / label produksi).
        // Dikirim balik agar UI bisa menyorot unit itu di modal — kosong bila yang
        // dipindai kode order/transaksi (mewakili seluruh order).
        $scannedStockIds = collect();

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

                $scannedStockIds = collect([(int) $stock->id]);
            }
        }

        // 3. Bukan order/unit → coba sebagai kode batch PRODUKSI (PRD-xxx). Label yang
        // menempel pada bungkus steril memakai kode ini, jadi satu scan pada bungkus
        // cukup untuk menemukan order terakhir yang memakai unit-unit batch tersebut.
        if (! $order) {
            $production = Production::where('code', $code)->first();
            if ($production) {
                $stockIds = ProductionItem::where('production_id', $production->id)
                    ->pluck('instrument_stock_id');

                $order = $stockIds->isEmpty()
                    ? null
                    : Order::whereHas('items', fn ($q) => $q->whereIn('instrument_stock_id', $stockIds))
                        ->with(self::DETAIL_RELATIONS)
                        ->latest()
                        ->first();

                if (! $order) {
                    return $this->error("Batch produksi \"{$code}\" belum masuk order manapun.", 404);
                }
            }
        }

        // 4. Bukan order/unit/batch → coba sebagai kode produksi pada LABEL steril:
        // kode batch + id production_item digabung tanpa pemisah (PRD260714031).
        if (! $order) {
            $stockIds = $this->stockIdsFromProductionLabel($code);
            if ($stockIds !== null) {
                $order = $stockIds->isEmpty()
                    ? null
                    : Order::whereHas('items', fn ($q) => $q->whereIn('instrument_stock_id', $stockIds))
                        ->with(self::DETAIL_RELATIONS)
                        ->latest()
                        ->first();

                if (! $order) {
                    return $this->error("Label produksi \"{$code}\" belum masuk order manapun.", 404);
                }

                $scannedStockIds = $stockIds;
            }
        }

        if (! $order) {
            return $this->error("Order, unit, atau batch produksi dengan kode \"{$code}\" tidak ditemukan.", 404);
        }

        // Timeline TIDAK di-embed di sini — di-lazy-load lewat GET orders/{order}/timeline
        // saat modal Pengembalian Instrumen menampilkan bagian Tracking.
        $this->attachProductionCodes($order);
        $this->attachBarcodeNos($order);
        $this->attachInstrumentNames($order);

        // Unit yang disorot di UI: hanya yang benar-benar ada di order ini.
        $order->setAttribute('scanned_stock_ids', $order->items
            ->pluck('instrument_stock_id')
            ->filter(fn ($id) => $scannedStockIds->contains((int) $id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
        );

        return $this->success('Detail order berhasil diambil.', $order);
    }

    /**
     * Terjemahkan kode produksi hasil scan LABEL steril (kode batch + id
     * production_item, mis. PRD260714031) menjadi unit-unit yang diwakili label itu.
     * Label satuan mewakili satu unit; label paket mewakili seluruh unit paket yang
     * sama dalam batch tersebut (id yang tercetak = item pertama paket itu).
     *
     * @return Collection<int,int>|null null bila kode bukan berbentuk label produksi
     */
    private function stockIdsFromProductionLabel(string $code): ?Collection
    {
        // Kode batch selalu berakhir angka, jadi titik potongnya tidak bisa ditebak
        // dari bentuk string — cocokkan ke batch yang kodenya menjadi awalan kode ini.
        $productions = Production::whereRaw('? LIKE CONCAT(code, ?)', [$code, '_%'])->get();

        foreach ($productions as $production) {
            $suffix = substr($code, strlen($production->code));
            if ($suffix === '' || ! ctype_digit($suffix)) {
                continue;
            }

            $item = ProductionItem::where('production_id', $production->id)
                ->where('id', (int) $suffix)
                ->first();
            if (! $item) {
                continue;
            }

            // Paket → satu label untuk seluruh unit paket tersebut.
            $items = $item->source === 'paket' && $item->package_name
                ? ProductionItem::where('production_id', $production->id)
                    ->where('source', 'paket')
                    ->where('package_name', $item->package_name)
                    ->get()
                : collect([$item]);

            return $items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->values();
        }

        return null;
    }

    /**
     * Lampirkan kode batch produksi (PRD-...) ke tiap unit order. Kode ini yang tercetak
     * pada bungkus steril, jadi petugas bisa mencocokkan barang fisik saat pengembalian.
     */
    private function attachProductionCodes(Order $order): void
    {
        $order->loadMissing('items');

        $codes = $this->productionCodeMap(
            $order->items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->all()
        );

        $order->items->each(
            fn (OrderItem $item) => $item->setAttribute(
                'production_code',
                $codes[(int) $item->instrument_stock_id] ?? null
            )
        );
    }

    /**
     * Lampirkan `barcode_no` (label fisik packaging_item) ke tiap unit order — dipakai
     * frontend untuk MENGELOMPOKKAN instrumen per label fisik saat pengembalian (bukan
     * per kode produksi). Diambil packaging_item TERBARU (belum di-void) tiap unit.
     */
    private function attachBarcodeNos(Order $order): void
    {
        $order->loadMissing('items');

        $map = PackagingItem::whereIn(
            'instrument_stock_id',
            $order->items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->unique()
        )
            ->where('disabled', false)
            ->whereNotNull('barcode_no')
            ->orderByDesc('id')
            ->get()
            ->groupBy('instrument_stock_id')
            ->map(fn ($g) => $g->first()->barcode_no); // orderByDesc → first = terbaru

        $order->items->each(
            fn (OrderItem $item) => $item->setAttribute(
                'barcode_no',
                $map[(int) $item->instrument_stock_id] ?? null
            )
        );
    }

    /**
     * Lampirkan NAMA instrumen dari SNAPSHOT production_item ke tiap unit order —
     * agar tampilan monitoring memakai nama saat unit diproduksi, bukan nama master
     * yang bisa berubah. Relasi master hanya cadangan bila snapshot kosong (data lama).
     */
    private function attachInstrumentNames(Order $order): void
    {
        $order->loadMissing('items.instrumentStock.instrument');

        $names = $this->productionNameMap(
            $order->items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->all()
        );

        $order->items->each(
            fn (OrderItem $item) => $item->setAttribute(
                'instrument_name',
                $names[(int) $item->instrument_stock_id] ?? $item->instrumentStock?->instrument?->name
            )
        );
    }

    /**
     * Lampirkan timeline tracking ke order: seluruh event (dibuat/diterima/dipindah/
     * dikembalikan) untuk semua order yang berbagi code_transaction (rantai handover).
     * Bila order belum punya code_transaction, ambil event order itu saja.
     */
    private function attachTimeline(Order $order, bool $includePipeline = true): void
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
                'detail' => null,
            ]);

        // Riwayat pipeline CSSD (Produksi → Cleaning → Sterilisasi → Simpan Rak) yang
        // menghasilkan unit yang dipinjam. Hanya disertakan bila $includePipeline
        // (mis. monitoring/scan). Di detail Order Instrumen timeline cukup siklus order
        // (Dibuat → Diterima CSSD → Terdistribusi → Dikembalikan) → pipeline dilewati.
        // Waktu pengembalian (event "dikembalikan" terbaru). Dipakai sebagai CUTOFF
        // pipeline: batch yang selesai setelah ini adalah pemrosesan ulang unit (siklus
        // baru), bukan bagian riwayat order ini → tidak diikutkan.
        $returnedAt = $orderEvents
            ->where('type', OrderEvent::TYPE_DIKEMBALIKAN)
            ->pluck('created_at')->filter()->max();

        $pipeline = collect();
        if ($includePipeline) {
            $stockIds = OrderItem::whereIn('order_id', $orderIds)
                ->pluck('instrument_stock_id')->filter()->unique();
            $pipeline = $this->pipelineTimeline($stockIds, $returnedAt);

            // Bila unit disimpan ke gudang steril SETELAH dikembalikan (penataan rak
            // menyusul), event "Di Gudang Steril" mengikuti waktu pengembalian — bukan
            // waktu penataan rak yang bisa terjadi berjam-jam kemudian.
            if ($returnedAt) {
                $pipeline = $pipeline->map(function ($e) use ($returnedAt) {
                    if ($e['type'] === 'disimpan' && $e['created_at'] && $e['created_at']->gt($returnedAt)) {
                        $e['created_at'] = $returnedAt;
                    }

                    return $e;
                });
            }
        }

        // Gabung & urut kronologis (produksi/steril/simpan terjadi sebelum dipinjam).
        // Event pipeline membawa kunci `sort` (urutan tahap dikunci) — event lain
        // memakai waktunya sendiri.
        $events = $orderEvents->concat($pipeline)
            ->sortBy(fn ($e) => $e['sort'] ?? (optional($e['created_at'])->timestamp ?? 0))
            ->values();

        $order->setAttribute('timeline', $events);
    }

    /**
     * Rangkai riwayat pipeline CSSD untuk sekumpulan unit (instrument_stock_id):
     * Produksi (batch terbaru tiap unit) → Cleaning (via production_code) →
     * Sterilisasi (batch terbaru tiap unit) → Simpan di Rak (instrument_storages).
     * Setiap tahap dibaca dari tabelnya langsung agar dijamin ter-record.
     *
     * @param  Collection<int,int>  $stockIds
     * @return Collection<int,array<string,mixed>>
     */
    private function pipelineTimeline($stockIds, $before = null): Collection
    {
        $stockIds = collect($stockIds)->filter()->unique()->values();
        if ($stockIds->isEmpty()) {
            return collect();
        }

        $events = collect();
        $seq = 0;
        $push = function (string $type, $at, ?string $actor, ?string $note, ?array $detail = null) use (&$events, &$seq) {
            $events->push([
                // Id sintetis negatif → unik & tidak bentrok dengan id OrderEvent.
                'id' => -(++$seq),
                'type' => $type,
                'room' => null,
                'actor' => $actor,
                'borrowed_by' => null,
                'note' => $note,
                'created_at' => $at,
                // Rincian untuk tombol "Detail" (nomor batch + waktu; packaging + rincian barcode).
                'detail' => $detail,
            ]);
        };

        // 1. Produksi — batch produksi TERBARU tiap unit (siklus aktif), dedup per batch.
        $prodItemIds = ProductionItem::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $stockIds)
            // Cutoff: hanya batch yang selesai SEBELUM order dikembalikan → ambil batch
            // siklus asli order ini, bukan pemrosesan ulang unit setelah pengembalian.
            ->when($before, fn ($q) => $q->whereHas('production', fn ($p) => $p->where('created_at', '<=', $before)))
            ->groupBy('instrument_stock_id')
            ->pluck('id');
        $productionIds = ProductionItem::whereIn('id', $prodItemIds)->pluck('production_id')->unique();
        // Cukup id + kode untuk timeline — rincian tiap tahap di-lazy-load saat Detail
        // diklik, jadi tak perlu memuat items/instrument di sini.
        $productions = Production::whereIn('id', $productionIds)->get();

        foreach ($productions as $p) {
            // Rincian tabel produksi di-LAZY-LOAD saat tombol Detail diklik (endpoint
            // GET master/production/detail?codes[]=...). Timeline hanya membawa nomor
            // batchnya, tanpa teks "Batch produksi …".
            $push('produksi', $p->created_at, $p->created_by, null, [
                'kind' => 'produksi',
                'code' => $p->code,
                'at' => $p->created_at,
            ]);
        }

        // 2. Cleaning — tahap washing yang mengalir dari batch produksi (via production_code).
        $washings = OrderWashing::whereIn('production_code', $productions->pluck('code'))->get();
        foreach ($washings as $w) {
            $type = match ($w->status) {
                OrderWashing::STATUS_SELESAI => 'selesai_cuci',
                OrderWashing::STATUS_GAGAL => 'gagal_cuci',
                default => 'diproses',
            };
            $at = $w->completed_at ?? $w->started_at ?? $w->created_at;
            // Tanpa teks "Cleaning …"; rincian tabel di-lazy-load saat Detail diklik.
            $push($type, $at, $w->completed_by ?? $w->started_by, null, [
                'kind' => 'cleaning',
                'code' => $w->code,
                'at' => $at,
            ]);
        }

        // 2b. Packaging — rincian per barcode_no di-LAZY-LOAD (endpoint by id packaging).
        $packagings = Packaging::whereIn('washing_code', $washings->pluck('code'))->get();
        foreach ($packagings as $pkg) {
            $at = $pkg->completed_at ?? $pkg->packaged_at ?? $pkg->started_at ?? $pkg->created_at;
            // Tanpa teks "Packaging …"; rincian barcode diambil saat Detail diklik.
            $push('packaging', $at, $pkg->completed_by ?? $pkg->operator ?? $pkg->started_by, null, [
                'kind' => 'packaging',
                'code' => $pkg->full_code,
                'id' => $pkg->id,
                'at' => $at,
            ]);
        }

        // 3. Sterilisasi — batch steril TERBARU tiap unit, dedup per batch.
        $sterItemIds = SterilizationItem::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $stockIds)
            // Cutoff sama seperti produksi: batch siklus asli order, bukan re-steril
            // setelah pengembalian.
            ->when($before, fn ($q) => $q->whereHas('sterilization', fn ($s) => $s->where('completed_at', '<=', $before)))
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
            $at = $s->completed_at ?? $s->sterilized_at ?? $s->created_at;
            // Tanpa teks "Sterilisasi …"; rincian tabel di-lazy-load saat Detail diklik.
            $push($type, $at, $s->completed_by ?? $s->created_by, null, [
                'kind' => 'steril',
                'code' => $s->code,
                'at' => $at,
            ]);
        }

        // 4. Simpan di Rak — penempatan TERBARU tiap unit di gudang steril, per rak.
        $storageIds = InstrumentStorage::selectRaw('MAX(id) as id')
            ->whereIn('instrument_stock_id', $stockIds)
            ->groupBy('instrument_stock_id')
            ->pluck('id');
        $storages = InstrumentStorage::whereIn('id', $storageIds)
            ->where('status', InstrumentStorage::STATUS_TERSIMPAN)
            ->get();
        foreach ($storages->groupBy('rack_code') as $rack => $rows) {
            $first = $rows->sortBy('stored_at')->first();
            $push('disimpan', $first->stored_at ?? $first->created_at, $first->created_by, "Disimpan di rak {$rack} ({$rows->count()} unit)");
        }

        // Setiap PROSES tampil SATU saja: bila unit berasal dari lebih dari satu batch,
        // ambil event TERBARU tiap tahap (Produksi/Cleaning/Packaging/Steril). Rincian
        // barcode dari semua batch digabung ke event packaging yang dipertahankan.
        // "Disimpan di rak" dibiarkan apa adanya (rak berbeda = lokasi, bukan proses ganda).
        $stageKey = fn (string $type) => match ($type) {
            'selesai_cuci', 'gagal_cuci', 'diproses' => 'cleaning',
            'steril', 'gagal_steril', 'disterilkan' => 'steril',
            default => $type,
        };
        $pipelineTypes = ['produksi', 'selesai_cuci', 'gagal_cuci', 'diproses', 'packaging', 'steril', 'gagal_steril', 'disterilkan'];
        $others = $events->reject(fn ($e) => in_array($e['type'], $pipelineTypes, true));
        $stageGroups = $events->filter(fn ($e) => in_array($e['type'], $pipelineTypes, true))
            ->groupBy(fn ($e) => $stageKey($e['type']));

        // Urutan tahap DIKUNCI: Produksi → Cleaning → Packaging → Steril, di-anchor ke
        // waktu produksi — supaya tidak terbalik walau event terbaru tiap tahap berasal
        // dari batch berbeda (mis. produksi batch-2 lebih baru dari cuci batch-1).
        $rank = ['produksi' => 0, 'cleaning' => 1, 'packaging' => 2, 'steril' => 3];
        $prodFirst = ($stageGroups['produksi'] ?? collect())->first();
        $base = $prodFirst ? (optional($prodFirst['created_at'] ?? null)->timestamp ?? 0) : 0;

        $deduped = collect();
        foreach ($stageGroups as $key => $group) {
            $keep = $group->sortBy(fn ($e) => optional($e['created_at'])->timestamp ?? 0)->values()->last();
            if ($key === 'packaging') {
                // Kumpulkan id semua packaging — dipakai frontend lazy-load rincian barcode.
                $keep['detail']['ids'] = $group
                    ->map(fn ($e) => $e['detail']['id'] ?? null)
                    ->filter()->unique()->values()->all();
            }
            if (in_array($key, ['produksi', 'cleaning', 'steril'], true)) {
                // Kumpulkan semua nomor batch tahap ini — dipakai frontend untuk
                // lazy-load rincian tabelnya saat Detail diklik.
                $keep['detail']['codes'] = $group
                    ->map(fn ($e) => $e['detail']['code'] ?? null)
                    ->filter()->unique()->values()->all();
            }
            // Kunci urut tahap — dipakai attachTimeline saat menggabung dgn event order.
            $keep['sort'] = $base + (($rank[$key] ?? 9) * 0.001);
            $deduped->push($keep);
        }

        return $deduped->concat($others)->values();
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
            // pengembalian (termasuk pengembalian dicicil sebagian unit). Siklus order
            // saja — pipeline CSSD hanya untuk monitoring/scan.
            $this->attachTimeline($order, includePipeline: false);
            $this->attachProductionCodes($order);
            $this->attachBarcodeNos($order);
            $this->attachInstrumentNames($order);

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

        // Pakai nomor TERTINGGI hari itu + 1, bukan jumlah baris: beberapa order dalam
        // satu rantai pinjam-alih sengaja berbagi code_transaction yang sama, jadi
        // menghitung baris akan melompati nomor sekaligus bisa bentrok saat ada lubang.
        // Order yang dihapus kode transaksinya sudah di-void (lihat Order::delete())
        // sehingga tidak cocok lagi dengan `$prefix%` — nomornya kembali bebas.
        $maxCode = Order::withTrashed()
            ->where('code_transaction', 'like', $prefix.'%')
            ->max('code_transaction');

        $seq = $maxCode ? ((int) substr($maxCode, strlen($prefix))) + 1 : 1;

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
