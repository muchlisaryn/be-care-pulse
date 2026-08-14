<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderRequestItem;
use App\Models\PackagingItem;
use App\Models\ProductionItem;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class MonitoringController extends Controller
{
    /**
     * Baris per halaman default tab "Distribution & Tracking" (lihat [tracking()]).
     * HARUS sama dengan konstanta `TRACKING_PER_PAGE` di monitoringSlice frontend —
     * nilainya memang dikirim eksplisit sebagai `per_page`, tapi kalau keduanya
     * berbeda, permintaan tanpa `per_page` akan memotong halaman dengan ukuran lain.
     */
    private const TRACKING_PER_PAGE = 10;

    /**
     * Monitoring per ruangan: daftar unit instrumen yang sedang dipinjam
     * di tiap ruangan (order berstatus "dipinjam" & item belum dikembalikan).
     */
    public function rooms(Request $request): JsonResponse
    {
        $rooms = Room::query()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
            )
            ->with(['orders' => function ($q) {
                // dipinjam = sudah di ruangan; digudang = sudah diterima & ditujukan
                // ke ruangan (siap diantar) — keduanya dianggap "aktif" untuk ruangan.
                $q->whereIn('status', [Order::STATUS_DIPINJAM, Order::STATUS_DIGUDANG])
                    ->with(['items' => function ($q) {
                        $q->where('is_returned', false)
                            ->with(['instrumentStock.instrument', 'instrumentStock.condition']);
                    }])
                    // Baris permintaan asli — sumber JUMLAH SET yang dipinjam. `items`
                    // hanya berisi unit fisik, jadi paket 2 set × 5 instrumen tampak
                    // sebagai 10 dan jumlah setnya tidak bisa disimpulkan dari sana.
                    ->with(['requestItems.catalog']);
            }])
            ->orderBy('name')
            ->paginate(20);

        // Nomor label fisik tiap unit — supaya kolom pencarian halaman monitoring
        // bisa menemukan order dari hasil scan barcode bungkus. Dikumpulkan sekali
        // untuk seluruh halaman (bukan per ruangan) agar tidak N+1.
        $barcodeByStock = $this->barcodeNoByStock(
            collect($rooms->items())
                ->flatMap(fn (Room $room) => $room->orders->flatMap(
                    fn (Order $order) => $order->items->pluck('instrument_stock_id')
                ))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        // Kelompokkan unit yang dipinjam per (order, katalog instrumen).
        // Order 5 unit katalog yang sama -> 1 baris qty 5; single item -> qty 1.
        $rooms->getCollection()->transform(function (Room $room) use ($barcodeByStock) {
            $groups = [];
            $unitCount = 0;
            $readyCount = 0; // unit pada order "digudang" (siap diantar, belum di ruangan)
            $txKeys = [];

            foreach ($room->orders as $order) {
                // Order siap-diantar (digudang): hitung jumlahnya saja; jangan masukkan
                // ke daftar `instruments` agar "Distribusi per Ruangan" tetap = yang dipinjam.
                if ($order->status === Order::STATUS_DIGUDANG) {
                    $readyCount += $order->items->count();

                    continue;
                }

                $built = $this->borrowedGroupsOfOrder($order, $barcodeByStock);
                $unitCount += $built['units'];
                // Kunci grup sudah memuat id order, jadi tidak pernah bentrok antar
                // order dalam satu ruangan.
                $groups += $built['groups'];

                if ($built['groups'] !== []) {
                    // Hitung transaksi unik berdasarkan no_transaction (code_transaction).
                    $txKeys[$order->code_transaction ?? ('ord-'.$order->id)] = true;
                }
            }

            $instruments = array_values($groups);

            return [
                'id' => $room->id,
                'code' => $room->code,
                'name' => $room->name,
                'borrowed_count' => $unitCount,
                // Unit yang sudah diterima & ditujukan ke ruangan ini tapi belum
                // didistribusikan (status order `digudang`).
                'ready_count' => $readyCount,
                'transaction_count' => count($txKeys),
                'instrument_count' => count($instruments),
                'instruments' => $instruments,
            ];
        });

        return $this->success('Data monitoring ruangan berhasil diambil.', $rooms);
    }

    /**
     * Baris "unit dipinjam" milik SATU order, dikelompokkan per (asal, nama paket,
     * katalog instrumen) — bentuk baris yang dipakai frontend monitoring & tracking.
     *
     * Dipakai bersama oleh [rooms()] (dikumpulkan per ruangan) dan [tracking()]
     * (dikumpulkan per order). Satu implementasi supaya kedua daftar tidak pernah
     * menghitung jumlah set / label dengan aturan yang berbeda.
     *
     * `units` = jumlah "unit dipinjam" versi tampilan: paket dihitung per SET, bukan
     * per unit fisik di dalamnya; instrumen satuan dihitung per unit.
     *
     * @param  array<int,string>  $barcodeByStock
     * @return array{groups: array<string,array<string,mixed>>, units: int}
     */
    private function borrowedGroupsOfOrder(Order $order, array $barcodeByStock): array
    {
        $groups = [];
        $unitCount = 0;

        // Jumlah SET per nama paket pada order ini, diambil dari baris permintaan
        // (quantity = jumlah set yang dipinjam). Nama katalog dipakai sebagai kunci —
        // sama dengan kunci pencocokan stok paket.
        $setsByPackage = [];
        foreach ($order->requestItems as $line) {
            if ($line->type !== 'paket') {
                continue;
            }
            $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
            $setsByPackage[$name] = ($setsByPackage[$name] ?? 0) + (int) $line->quantity;
        }

        // Jumlah SET nyata tiap paket = banyaknya NOMOR LABEL kemasan berbeda
        // di antara unit yang masih dipinjam (satu label = satu bungkus = satu
        // set). Lebih akurat daripada baris permintaan saat order dikembalikan
        // sebagian atau berasal dari pinjam-alih (tanpa baris permintaan);
        // unit tanpa label (data lama) jatuh ke jumlah pada baris permintaan.
        $labelsByPackage = [];
        foreach ($order->items as $item) {
            if ($item->source !== 'paket') {
                continue;
            }
            $pkg = $item->package_name ?? 'Paket';
            $labelsByPackage[$pkg] ??= [];
            if ($barcode = $barcodeByStock[(int) $item->instrument_stock_id] ?? null) {
                $labelsByPackage[$pkg][$barcode] = true;
            }
        }
        $setsOf = function (string $pkg) use ($labelsByPackage, $setsByPackage): int {
            $n = count($labelsByPackage[$pkg] ?? []);

            return $n > 0 ? $n : ($setsByPackage[$pkg] ?? 1);
        };

        // Paket sudah dihitung (per nama) supaya unit fisik di dalamnya
        // tidak menambah "unit dipinjam" berulang kali.
        $countedPackages = [];

        foreach ($order->items as $item) {
            $stock = $item->instrumentStock;
            $instrument = $stock?->instrument;
            if (! $instrument) {
                continue;
            }

            // Jumlah "unit dipinjam": paket dihitung per SET (bukan per unit
            // fisik di dalamnya), instrumen satuan dihitung per unit.
            if ($item->source === 'paket') {
                $pkg = $item->package_name ?? 'Paket';
                if (! isset($countedPackages[$pkg])) {
                    $countedPackages[$pkg] = true;
                    $unitCount += $setsOf($pkg);
                }
            } else {
                $unitCount++;
            }

            // Pisahkan per asal (satuan/paket) & nama paket agar bisa
            // dikelompokkan "detail per paket" di frontend monitoring.
            $key = $order->id.'-'.$item->source.'-'.($item->package_name ?? '').'-'.$instrument->id;

            $groups[$key] ??= [
                'order_code' => $order->code,
                'code_transaction' => $order->code_transaction,
                'borrowed_by' => $order->borrowed_by ?? $order->created_by,
                // Identitas pasien (rawat inap) — ditampilkan di kartu Daftar Order.
                'patient_name' => $order->patient_name,
                'medical_record_no' => $order->medical_record_no,
                'order_date' => $order->order_date,
                'order_time' => optional($order->created_at)->format('H:i'),
                'return_plan_date' => $order->return_plan_date,
                'source' => $item->source,
                'package_name' => $item->package_name,
                // Jumlah SET paket ini pada order (null utk baris satuan).
                // `qty` di bawah tetap jumlah unit fisik.
                'package_sets' => $item->source === 'paket'
                    ? $setsOf($item->package_name ?? 'Paket')
                    : null,
                'instrument' => [
                    'id' => $instrument->id,
                    'code' => $instrument->code,
                    'name' => $instrument->name,
                ],
                'qty' => 0,
                'units' => [],
            ];

            $groups[$key]['qty']++;
            $groups[$key]['units'][] = [
                'instrument_stock_id' => $stock->id,
                'code' => $stock->code,
                'status' => $stock->status,
                // Nomor label fisik bungkus steril unit ini (bisa null bila
                // unit belum pernah melewati tahap packaging).
                'barcode_no' => $barcodeByStock[(int) $stock->id] ?? null,
                'condition' => $stock->condition
                    ? ['id' => $stock->condition->id, 'name' => $stock->condition->name]
                    : null,
            ];
        }

        return ['groups' => $groups, 'units' => $unitCount];
    }

    /**
     * Nomor label fisik (`packaging_item.barcode_no`) TERBARU tiap unit, di-key oleh
     * instrument_stock_id. Label yang sudah di-void (`disabled`) diabaikan. Satu unit
     * bisa punya beberapa label lintas siklus, jadi diambil yang paling akhir.
     *
     * @param  array<int,int>  $stockIds
     * @return array<int,string>
     */
    private function barcodeNoByStock(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return PackagingItem::whereIn('instrument_stock_id', $stockIds)
            ->where('disabled', false)
            ->whereNotNull('barcode_no')
            ->orderByDesc('id')
            ->get(['instrument_stock_id', 'barcode_no'])
            ->groupBy('instrument_stock_id')
            ->map(fn ($g) => $g->first()->barcode_no) // orderByDesc → first = terbaru
            ->all();
    }

    /**
     * Order masuk dari menu Order Instrumen: order yang baru diajukan
     * (belum dipinjam) — lintas user, untuk dipantau CSSD di halaman monitoring.
     */
    public function incoming(Request $request): JsonResponse
    {
        $orders = Order::with([
            'room',
            'user',
            'requestItems.instrument',
            'requestItems.catalog.items.instrument',
        ])
            ->where('status', Order::STATUS_DIAJUKAN)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest()
            ->paginate(20);

        // Sertakan total jumlah unit yang diminta (akumulasi quantity baris permintaan).
        $orders->getCollection()->transform(function (Order $order) {
            return [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
                // Identitas pasien (rawat inap) — ditampilkan di kartu Daftar Order.
                'patient_name' => $order->patient_name,
                'medical_record_no' => $order->medical_record_no,
                'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
                'order_date' => $order->order_date,
                'return_plan_date' => $order->return_plan_date,
                'note' => $order->note,
                'requested_qty' => (int) $order->requestItems->sum('quantity'),
                'request_lines' => $order->requestItems->count(),
                'items' => $order->requestItems->map(fn ($it) => [
                    'type' => $it->type,
                    'name' => $it->type === 'paket'
                        ? ($it->package_name ?? $it->catalog?->name ?? 'Paket')
                        : ($it->instrument?->name ?? "Instrumen #{$it->instrument_id}"),
                    'quantity' => $it->quantity,
                    // Untuk paket: rincian instrumen di dalam satu paket (komposisi katalog).
                    'contents' => $it->type === 'paket' && $it->catalog
                        ? $it->catalog->items->map(fn ($ci) => [
                            'instrument' => $ci->instrument?->name ?? "Instrumen #{$ci->instrument_id}",
                            'code' => $ci->instrument?->code,
                            'quantity' => (int) $ci->quantity,
                        ])->values()
                        : [],
                ])->values(),
            ];
        });

        return $this->success('Data order masuk berhasil diambil.', $orders);
    }

    /**
     * JUMLAH order masuk saja — sumber angka badge notifikasi di sidebar. Dipisah dari
     * `incoming()` karena dipanggil sering (saat tab kembali fokus, setelah order
     * diterima/dibatalkan/dihapus): endpoint ini hanya `count()`, tanpa memuat 20 order
     * beserta relasinya. Penyaringnya WAJIB sama persis dengan `incoming()` — kalau
     * `incoming()` berubah, ubah juga di sini agar badge tidak pernah berbeda dari
     * daftar yang ditampilkan.
     */
    public function incomingCount(): JsonResponse
    {
        return $this->success('Jumlah order masuk berhasil diambil.', [
            'count' => Order::where('status', Order::STATUS_DIAJUKAN)->count(),
        ]);
    }

    /**
     * Order yang sudah dikembalikan (selesai) — tetap dipajang di halaman monitoring
     * sebagai riwayat. Detail unit + kondisi diambil lewat endpoint scan saat dibuka.
     */
    public function returned(Request $request): JsonResponse
    {
        $orders = Order::with(['room', 'user', 'items:id,order_id,instrument_stock_id,source,package_name'])
            ->withCount('items')
            ->where('status', Order::STATUS_DIKEMBALIKAN)
            ->when(
                $request->search,
                fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")
                    ->orWhere('borrowed_by', 'like', "%{$s}%")
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', "%{$s}%")))
            )
            ->latest('updated_at')
            ->paginate(20);

        // Nomor label kemasan seluruh unit pada halaman ini — dasar hitung SET paket
        // (satu label = satu bungkus = satu set). Dikumpulkan sekali agar tidak N+1.
        $barcodeByStock = $this->barcodeNoByStock(
            collect($orders->items())
                ->flatMap(fn (Order $order) => $order->items->pluck('instrument_stock_id'))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        $setsFallback = $this->requestedSetsByOrder(
            collect($orders->items())->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $orders->getCollection()->transform(
            fn (Order $order) => $this->returnedRowPayload($order, $barcodeByStock, $setsFallback)
        );

        return $this->success('Data order dikembalikan berhasil diambil.', $orders);
    }

    /**
     * Bentuk satu baris "order dikembalikan" (kartu riwayat). Dipakai bersama oleh
     * [returned()] dan [tracking()] supaya kartu riwayat di kedua endpoint tidak
     * pernah berbeda isinya.
     *
     * @param  array<int,string>  $barcodeByStock
     * @param  array<int,array<string,int>>  $setsFallback
     * @return array<string,mixed>
     */
    private function returnedRowPayload(Order $order, array $barcodeByStock, array $setsFallback): array
    {
        // Ringkasan kartu: paket per SET, satuan per UNIT — aturan yang sama
        // dengan kartu order aktif & kartu statistik.
        $counts = $this->countAsSetsAndUnits($order->items, $barcodeByStock, $setsFallback);

        return [
            'id' => $order->id,
            'code' => $order->code,
            'code_transaction' => $order->code_transaction,
            'status' => $order->status,
            'borrowed_by' => $order->borrowed_by ?? $order->user?->name,
            // Identitas pasien (rawat inap) — ditampilkan di kartu Daftar Order.
            'patient_name' => $order->patient_name,
            'medical_record_no' => $order->medical_record_no,
            'room' => $order->room ? ['id' => $order->room->id, 'name' => $order->room->name] : null,
            'order_date' => $order->order_date,
            'return_plan_date' => $order->return_plan_date,
            // Perkiraan waktu pengembalian selesai = terakhir kali order diperbarui.
            'returned_at' => $order->updated_at,
            'total_units' => (int) $order->items_count,
            // Jumlah set paket & unit satuan untuk ringkasan kartu.
            'total_sets' => $counts['sets'],
            'total_satuan' => $counts['units'],
        ];
    }

    /**
     * Daftar tab "Distribution & Tracking" sebagai SATU daftar yang dipaginasi di
     * SERVER: order yang sedang DIPINJAM lebih dulu (pekerjaan berjalan), lalu
     * RIWAYAT order yang sudah DIKEMBALIKAN.
     *
     * Sebelumnya frontend menggabung dua endpoint (`monitoring/rooms` +
     * `monitoring/returned`) lalu memotong hasilnya di klien. Karena kedua endpoint
     * itu sendiri dipaginasi (20 ruangan / 20 order per halaman), "halaman 2" di layar
     * hanya memotong data yang kebetulan sudah terkirim — order di luar itu tidak
     * pernah bisa dibuka. Di sini urutan, penyaringan, dan potongan halamannya
     * ditentukan server sehingga jumlah halaman & isinya benar.
     *
     * Kedua kelompok punya kolom tanggal dan urutan yang berbeda (dipinjam memakai
     * `order_date`, riwayat memakai `updated_at`), jadi paginasinya dirakit manual:
     * kelompok pertama dihitung dulu, lalu sisa kuota halaman diambil dari kelompok
     * kedua. Batas halaman tetap tepat tanpa perlu UNION.
     *
     * Filter: ?search (kode order/transaksi, peminjam, ruangan, nama/kode instrumen,
     * kode unit, nomor label kemasan), ?from & ?to (tanggal aktivitas), ?per_page
     * (default 10). Rentang tanggalnya sengaja dibandingkan dengan tanggal AKTIVITAS
     * TERAKHIR tiap baris — sama seperti [counts()] — supaya order lama yang baru
     * dikembalikan tetap muncul pada rentang "7 hari terakhir".
     */
    public function tracking(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = min((int) ($request->per_page ?: self::TRACKING_PER_PAGE), 100);
        $page = max((int) ($request->page ?: 1), 1);
        $search = trim((string) $request->search);
        $from = $request->input('from');
        $to = $request->input('to');

        // Nomor label kemasan dicari lewat tabel packaging_item, bukan kolom order —
        // id unit hasil pencocokannya dipakai sebagai salah satu syarat OR di bawah.
        $barcodeStockIds = $search === '' ? [] : PackagingItem::where('barcode_no', 'like', "%{$search}%")
            ->whereNull('deleted_by')
            ->pluck('instrument_stock_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $borrowedTotal = $this->trackingQuery(
            Order::STATUS_DIPINJAM, 'order_date', $from, $to, $search, $barcodeStockIds
        )->count();
        $returnedTotal = $this->trackingQuery(
            Order::STATUS_DIKEMBALIKAN, 'updated_at', $from, $to, $search, $barcodeStockIds
        )->count();

        $offset = ($page - 1) * $perPage;

        // Potongan kelompok "dipinjam" untuk halaman ini (bisa kosong pada halaman
        // yang seluruhnya berisi riwayat).
        $borrowedOrders = collect();
        if ($offset < $borrowedTotal) {
            $borrowedOrders = $this->trackingQuery(
                Order::STATUS_DIPINJAM, 'order_date', $from, $to, $search, $barcodeStockIds
            )
                ->with([
                    'room:id,name',
                    'items' => fn ($q) => $q->where('is_returned', false)
                        ->with(['instrumentStock.instrument', 'instrumentStock.condition']),
                    'requestItems.catalog',
                ])
                ->orderByDesc('order_date')
                // Tie-break wajib: order_date hanya DATE — tanpa ini urutan barisnya
                // bisa bergeser antar halaman.
                ->orderByDesc('id')
                ->skip($offset)
                ->take(min($perPage, $borrowedTotal - $offset))
                ->get();
        }

        // Sisa kuota halaman diisi riwayat, mulai dari posisi setelah seluruh
        // kelompok "dipinjam" habis.
        $returnedOrders = collect();
        $remaining = $perPage - $borrowedOrders->count();
        if ($remaining > 0) {
            $returnedOrders = $this->trackingQuery(
                Order::STATUS_DIKEMBALIKAN, 'updated_at', $from, $to, $search, $barcodeStockIds
            )
                ->with(['room:id,name', 'user:id,name', 'items:id,order_id,instrument_stock_id,source,package_name'])
                ->withCount('items')
                ->latest('updated_at')
                ->orderByDesc('id')
                ->skip(max(0, $offset - $borrowedTotal))
                ->take($remaining)
                ->get();
        }

        // Label kemasan seluruh unit pada halaman ini — sekali query untuk kedua
        // kelompok agar tidak N+1.
        $barcodeByStock = $this->barcodeNoByStock(
            $borrowedOrders->concat($returnedOrders)
                ->flatMap(fn (Order $order) => $order->items->pluck('instrument_stock_id'))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        $setsFallback = $this->requestedSetsByOrder(
            $returnedOrders->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $rows = [];

        foreach ($borrowedOrders as $order) {
            $built = $this->borrowedGroupsOfOrder($order, $barcodeByStock);

            $rows[] = [
                'kind' => 'borrowed',
                'order_id' => $order->id,
                'order_code' => $order->code,
                // Baris unit dikirim RATA (belum dikelompokkan per paket/satuan) —
                // frontend memakai pengelompok yang sama dengan monitoring ruangan,
                // jadi bentuknya sengaja dibuat identik dengan `monitoring/rooms`.
                'instruments' => array_values(array_map(
                    fn (array $group) => $group + ['room' => $order->room?->name],
                    $built['groups']
                )),
            ];
        }

        foreach ($returnedOrders as $order) {
            $rows[] = [
                'kind' => 'returned',
                'order_id' => $order->id,
                'order_code' => $order->code,
                'order' => $this->returnedRowPayload($order, $barcodeByStock, $setsFallback),
            ];
        }

        $paginator = new LengthAwarePaginator(
            $rows,
            $borrowedTotal + $returnedTotal,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->success('Data tracking distribusi berhasil diambil.', $paginator);
    }

    /**
     * Query dasar satu kelompok baris tracking: order pada satu status, disaring
     * rentang tanggal (kolomnya berbeda per kelompok) dan kata kunci.
     *
     * Tiap syarat OR pencarian dibungkus closure sendiri — tanpa itu, kondisi
     * relasinya bercampur dengan syarat OR di dalam subquery `whereHas` dan
     * hasilnya ikut memuat order yang tidak dicari.
     *
     * @param  array<int,int>  $barcodeStockIds
     */
    private function trackingQuery(
        string $status,
        string $dateColumn,
        ?string $from,
        ?string $to,
        string $search,
        array $barcodeStockIds
    ) {
        $like = "%{$search}%";

        return Order::where('status', $status)
            ->when($from, fn ($q, $v) => $q->whereDate($dateColumn, '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate($dateColumn, '<=', $v))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($like, $barcodeStockIds) {
                $w->where('code', 'like', $like)
                    ->orWhere('code_transaction', 'like', $like)
                    ->orWhere('borrowed_by', 'like', $like)
                    ->orWhereHas('room', fn ($r) => $r->where('name', 'like', $like))
                    ->orWhereHas('items.instrumentStock', fn ($s) => $s->where('code', 'like', $like))
                    ->orWhereHas(
                        'items.instrumentStock.instrument',
                        fn ($i) => $i->where(fn ($x) => $x->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like))
                    );

                if (! empty($barcodeStockIds)) {
                    $w->orWhereHas('items', fn ($i) => $i->whereIn('instrument_stock_id', $barcodeStockIds));
                }
            }));
    }

    /**
     * JUMLAH order per tahap untuk badge angka pada tab halaman Tracking Order.
     *
     * Murni `count()` di database — tidak memuat satu pun baris order beserta
     * relasinya. Dulu angka ini didapat frontend dengan mengambil SELURUH halaman
     * daftar lalu menghitung panjang arraynya; untuk gudang dengan ribuan order itu
     * berat dan lambat, padahal yang dibutuhkan cuma angka.
     *
     * Rentang tanggal opsional (?from=&to=, format YYYY-MM-DD) mengikuti filter di
     * halaman agar angka badge selalu sama dengan isi daftarnya:
     *   - siap distribusi → `processed_at` (saat diterima CSSD)
     *   - dipinjam        → `order_date` (tanggal pinjam)
     *   - dikembalikan    → `updated_at` (perkiraan waktu pengembalian selesai)
     * "Order masuk" TIDAK ikut disaring: order yang belum diterima harus selalu
     * terlihat, setua apa pun tanggalnya.
     */
    public function counts(Request $request): JsonResponse
    {
        $from = $request->input('from');
        $to = $request->input('to');

        $between = fn ($query, string $column) => $query
            ->when($from, fn ($q, $v) => $q->whereDate($column, '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate($column, '<=', $v));

        return $this->success('Jumlah order per tahap berhasil diambil.', [
            'masuk' => Order::where('status', Order::STATUS_DIAJUKAN)->count(),
            'siap_distribusi' => $between(
                Order::where('status', Order::STATUS_DIGUDANG),
                'processed_at'
            )->count(),
            'dipinjam' => $between(
                Order::where('status', Order::STATUS_DIPINJAM),
                'order_date'
            )->count(),
            'dikembalikan' => $between(
                Order::where('status', Order::STATUS_DIKEMBALIKAN),
                'updated_at'
            )->count(),
        ]);
    }

    /**
     * Ringkasan per RUANGAN untuk kartu "Distribusi per Ruangan": nama ruangan +
     * jumlah instrumen dipinjam & terlambat. Tanpa daftar instrumennya.
     *
     * Dipisah dari [rooms()] yang memuat seluruh unit beserta relasi instrumen,
     * kondisi, dan baris permintaan tiap ruangan — payload itu hanya dibutuhkan saat
     * daftar order dibuka, bukan untuk memajang angka di kartu. Di sini yang dibaca
     * hanya baris order_item + kolom seperlunya, jadi jauh lebih ringan.
     *
     * Sengaja TIDAK dipaginasi: ini agregat (satu baris per ruangan yang sedang
     * meminjam), sama seperti endpoint ringkasan lain — frontend memang butuh
     * seluruhnya sekaligus untuk kartu & modal "semua ruangan".
     */
    public function roomsSummary(): JsonResponse
    {
        $items = $this->borrowedItems();
        $barcodes = $this->barcodeNoByStock($this->stockIdsOf($items));
        $fallback = $this->requestedSetsByOrder($items->pluck('order_id')->unique()->values()->all());

        $today = now()->startOfDay();
        $rooms = Room::whereIn('id', $items->pluck('order.room_id')->filter()->unique())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $summary = $items
            ->groupBy(fn (OrderItem $item) => (int) ($item->order?->room_id ?? 0))
            ->map(function ($roomItems, $roomId) use ($rooms, $barcodes, $fallback, $today) {
                // Kunci hasil groupBy selalu string — dikembalikan ke int agar cocok
                // dengan peta ruangan yang di-key oleh id.
                $room = $rooms->get((int) $roomId);
                if (! $room) {
                    return null;
                }

                $counts = $this->countAsSetsAndUnits($roomItems, $barcodes, $fallback);
                $overdue = $this->countAsSetsAndUnits(
                    $roomItems->filter(fn (OrderItem $i) => $i->order?->return_plan_date
                        && $i->order->return_plan_date->startOfDay()->lt($today)),
                    $barcodes,
                    $fallback
                );

                return [
                    'id' => $room->id,
                    'code' => $room->code,
                    'name' => $room->name,
                    // Aturan hitung sama dengan kartu statistik: paket per SET, satuan per UNIT.
                    'borrowed_count' => $counts['sets'] + $counts['units'],
                    'overdue_count' => $overdue['sets'] + $overdue['units'],
                ];
            })
            ->filter()
            ->sortByDesc('borrowed_count')
            ->values();

        return $this->success('Ringkasan distribusi per ruangan berhasil diambil.', $summary);
    }

    /**
     * Ringkasan jumlah instrumen yang SEDANG DIPINJAM — sumber angka kartu statistik
     * "Instrumen Sedang Dipinjam" di halaman Tracking Order.
     *
     * ATURAN HITUNG sengaja disamakan dengan kartu "Instrumen di Gudang Steril"
     * (StorageController@summary): baris `paket` dihitung per SET (satu nomor label
     * kemasan = satu bungkus = satu set), baris `satuan` dihitung per UNIT fisik. Jadi
     * satu set berisi 5 instrumen tetap bernilai 1. Endpointnya sengaja DIPISAH dari
     * gudang steril — datanya beda (order dipinjam vs pool gudang), hanya cara
     * hitungnya yang sama.
     *
     * Dipisah pula dari `rooms()` karena kartu statistik harus memuat SELURUH order
     * dipinjam, bukan hanya 20 ruangan pada halaman pertama daftar ruangan — dan
     * karena angkanya tidak butuh daftar unit beserta relasinya, hanya hitungannya.
     *
     * Mengisi KETIGA kartu statistik sekaligus (instrumen dipinjam, order aktif,
     * instrumen terlambat) supaya halaman cukup satu permintaan, bukan menghitung
     * sendiri dari daftar ruangan yang dimuat penuh.
     */
    public function borrowedSummary(): JsonResponse
    {
        $items = $this->borrowedItems();
        $barcodes = $this->barcodeNoByStock($this->stockIdsOf($items));
        $fallback = $this->requestedSetsByOrder($items->pluck('order_id')->unique()->values()->all());

        $counts = $this->countAsSetsAndUnits($items, $barcodes, $fallback);

        // Terlambat = masih dipinjam tapi rencana kembali sudah lewat (turunan, bukan
        // status di database) — ambang harinya sama dengan tampilan frontend.
        $today = now()->startOfDay();
        $overdue = $this->countAsSetsAndUnits(
            $items->filter(fn (OrderItem $i) => $i->order?->return_plan_date
                && $i->order->return_plan_date->startOfDay()->lt($today)),
            $barcodes,
            $fallback
        );

        return $this->success('Ringkasan instrumen dipinjam berhasil diambil.', [
            // Angka yang dipajang kartu = set paket + unit satuan.
            'borrowed' => $counts['sets'] + $counts['units'],
            'sets' => $counts['sets'],
            'units' => $counts['units'],
            // Order aktif = order yang masih punya unit belum dikembalikan.
            'orders' => $items->pluck('order_id')->unique()->count(),
            'overdue' => $overdue['sets'] + $overdue['units'],
        ]);
    }

    /**
     * Baris unit yang SEDANG DIPINJAM (belum dikembalikan) beserta kolom order yang
     * dibutuhkan penghitung: ruangan & rencana kembali. Basis bersama kartu statistik
     * dan ringkasan per ruangan — kolomnya sengaja dibatasi agar ringan.
     *
     * @return Collection<int,OrderItem>
     */
    private function borrowedItems(): Collection
    {
        return OrderItem::query()
            ->where('is_returned', false)
            ->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_DIPINJAM))
            ->with('order:id,room_id,return_plan_date')
            ->get(['id', 'order_id', 'instrument_stock_id', 'source', 'package_name']);
    }

    /**
     * Id unit fisik unik dari sekumpulan baris order.
     *
     * @param  Collection<int,OrderItem>  $items
     * @return array<int,int>
     */
    private function stockIdsOf(Collection $items): array
    {
        return $items->pluck('instrument_stock_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Jumlah instrumen menurut aturan tampilan: baris `paket` dihitung per SET,
     * baris `satuan` dihitung per unit fisik.
     *
     * Satu set = satu nomor label kemasan (`packaging_item.barcode_no`) berbeda di
     * dalam satu paket pada satu order — satu label = satu bungkus = satu set. Bila
     * seluruh unit sebuah paket tidak punya nomor label (data lama sebelum tahap
     * packaging), jumlahnya jatuh ke baris permintaan (`$setsFallback`), lalu ke 1 —
     * jangan pernah ke jumlah unit, supaya isi set tidak dihitung satu per satu.
     *
     * @param  Collection<int,OrderItem>  $items
     * @param  array<int,string>  $barcodeByStock  instrument_stock_id → nomor label
     * @param  array<string,int>  $setsFallback  "orderId|namaPaket" → jumlah set diminta
     * @return array{sets: int, units: int}
     */
    private function countAsSetsAndUnits(Collection $items, array $barcodeByStock, array $setsFallback = []): array
    {
        $units = 0;
        $labels = [];

        foreach ($items as $item) {
            if ($item->source !== 'paket') {
                $units++;

                continue;
            }

            $key = $item->order_id.'|'.($item->package_name ?? 'Paket');
            $labels[$key] ??= [];
            if ($barcode = $barcodeByStock[(int) $item->instrument_stock_id] ?? null) {
                $labels[$key][$barcode] = true;
            }
        }

        $sets = 0;
        foreach ($labels as $key => $barcodes) {
            $sets += count($barcodes) > 0 ? count($barcodes) : ($setsFallback[$key] ?? 1);
        }

        return ['sets' => $sets, 'units' => $units];
    }

    /**
     * Jumlah SET paket yang DIMINTA tiap order, di-key "orderId|namaPaket" — cadangan
     * penghitung set untuk unit paket yang belum punya nomor label kemasan.
     *
     * @param  array<int,int>  $orderIds
     * @return array<string,int>
     */
    private function requestedSetsByOrder(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $map = [];
        OrderRequestItem::with('catalog:id,name')
            ->whereIn('order_id', $orderIds)
            ->where('type', 'paket')
            ->get(['id', 'order_id', 'instrument_catalog_id', 'package_name', 'quantity'])
            ->each(function (OrderRequestItem $line) use (&$map) {
                $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                $key = $line->order_id.'|'.$name;
                $map[$key] = ($map[$key] ?? 0) + (int) $line->quantity;
            });

        return $map;
    }

    /**
     * Papan monitor (display TV): daftar order aktif yang dipajang di layar gudang.
     * Item dikelompokkan per (order, instrumen) lalu dihitung jumlah unitnya (QTY).
     *
     * `?room_id=` menyaring ke satu ruangan — dipakai papan per-ruangan
     * (`/monitor/{ruangan_id}`) yang menyegarkan diri tiap 20 detik. Tanpa itu papan
     * satu ruangan menarik seluruh order aktif rumah sakit lalu membuang hampir
     * semuanya di browser.
     */
    public function board(Request $request): JsonResponse
    {
        $request->validate([
            'room_id' => 'nullable|integer',
        ]);
        $roomId = $request->input('room_id');

        // Urutan tahap pipeline — HANYA untuk mengurutkan baris di layar & memilih
        // sumber angka QTY, bukan untuk memilih order mana yang tampil (lihat query
        // di bawah: penyaringnya jejak waktu, bukan status).
        $statuses = [
            Order::STATUS_DIAJUKAN,
            Order::STATUS_PENCUCIAN,
            Order::STATUS_PENGEMASAN,
            Order::STATUS_SELESAI,
            Order::STATUS_STERILISASI,
            Order::STATUS_STERIL,
            Order::STATUS_DIGUDANG,
            Order::STATUS_DIPINJAM,
        ];
        $stageOrder = array_flip($statuses);

        // Papan hanya memajang PEKERJAAN YANG MASIH BERJALAN. Penyaringnya dibaca
        // dari kolom audit/jejak waktu, BUKAN dari kolom `status`: status ditulis
        // ulang di banyak titik sepanjang alur CSSD dan bisa tertinggal, sedangkan
        // jejak di bawah hanya ditulis sekali tepat saat kejadiannya.
        //
        //  - dibatalkan → `canceled_at` terisi (juga `deleted_by` lewat global scope
        //    `active` dari HasAuditColumns untuk order yang dihapus);
        //  - masih memegang unit yang belum kembali → PEKERJAAN BERJALAN, tampil.
        //    Dibaca per unit (`order_item.is_returned`), bukan dari `return_actual_date`
        //    di header, karena pengembalian boleh dicicil: order dengan sebagian unit
        //    masih di ruangan tetap pekerjaan berjalan;
        //  - belum punya unit sama sekali DAN belum pernah keluar gudang maupun
        //    dikembalikan → order yang masih di tahap paling awal (baru diajukan),
        //    unitnya memang belum dialokasikan. Tampil.
        //
        // Syarat "belum pernah keluar/kembali" pada cabang kedua itu WAJIB. Tanpa itu,
        // order yang sudah dikembalikan tapi tidak punya jejak `processed_at` — order
        // lama dari sebelum kolom itu ada, dan SETIAP order hasil pinjam-alih yang
        // memang tidak pernah melewati penerimaan CSSD — ikut lolos dan nyangkut di
        // papan selamanya. Cabang ini juga menutup order sumber pinjam-alih yang
        // seluruh unitnya sudah berpindah ke order peminjam baru (unitnya dipindah,
        // bukan ditandai kembali): `distributed_at`-nya sudah terisi.
        $orders = Order::query()
            ->whereNull('canceled_at')
            ->when($roomId, fn ($q, $v) => $q->where('room_id', $v))
            ->where(fn ($q) => $q
                ->whereHas('items', fn ($i) => $i->where('is_returned', false))
                ->orWhere(fn ($w) => $w->whereDoesntHave('items')
                    ->whereNull('distributed_at')
                    ->whereNull('return_actual_date')))
            ->with([
                // Kolom dibatasi seperlunya — papan ini disegarkan tiap 20 detik di
                // layar TV, jadi tiap kolom yang tidak dipakai ikut jadi beban tetap.
                'room:id,name',
                'items:id,order_id,instrument_stock_id,source,package_name,is_returned',
                'items.instrumentStock:id,instrument_id',
                'items.instrumentStock.instrument:id,name',
                'requestItems:id,order_id,type,instrument_id,instrument_catalog_id,package_name,quantity',
                'requestItems.instrument:id,name',
                'requestItems.catalog:id,name',
            ])
            // Hanya kolom yang benar-benar dipajang/dipakai mengurutkan.
            ->get(['id', 'code', 'code_transaction', 'borrowed_by', 'created_by', 'order_date', 'created_at', 'room_id', 'status'])
            ->sortBy(fn (Order $o) => sprintf('%02d-%s-%06d', $stageOrder[$o->status] ?? 99, (string) $o->order_date, $o->id))
            ->values();

        // Penanda SET tiap unit paket: satu set = satu `package_no` dalam satu batch
        // produksi (semua unit isi set berbagi nomor yang sama). Dipetakan sekali
        // untuk seluruh papan agar tidak query per order.
        $setKeyByStock = $this->setKeyByStock(
            $orders->flatMap(fn (Order $o) => $o->items->where('source', 'paket')->pluck('instrument_stock_id'))
                ->filter()->unique()->values()->all()
        );

        // Nama peminjam TERAKHIR tiap order — satu query untuk seluruh papan (bukan
        // per baris). Pinjam-alih memindahkan unit ke ORDER BARU milik peminjam baru
        // dan mencatat event `dipindah` di sana, jadi nama yang benar untuk tiap baris
        // adalah nama pada event TERBARU milik order itu sendiri; nama peminjam awal
        // tetap tinggal di order sumbernya.
        //
        // Diurut menaik lalu di-pluck: baris belakangan menimpa yang lebih lama, jadi
        // yang tersisa per order adalah event terbarunya.
        $latestBorrowerByOrder = OrderEvent::whereIn('order_id', $orders->pluck('id'))
            ->whereNotNull('borrowed_by')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('borrowed_by', 'order_id');

        $rows = $orders->map(function (Order $order) use ($setKeyByStock, $latestBorrowerByOrder) {
            $lines = [];

            // QTY papan monitor: PAKET dihitung per SET, SATUAN per unit fisik.
            // Baris permintaan dipakai sebagai cadangan bila set tidak bisa
            // disimpulkan dari unit (lihat pemakaian $setKeyByStock di bawah).
            $setsByPackage = [];
            foreach ($order->requestItems as $line) {
                if ($line->type !== 'paket') {
                    continue;
                }
                $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                $setsByPackage[$name] = ($setsByPackage[$name] ?? 0) + (int) $line->quantity;
            }

            // Sumber angka QTY juga tidak lagi ditentukan status: begitu unit fisiknya
            // dialokasikan (ada `order_item` yang belum kembali), itulah yang dihitung;
            // sebelum itu papan memakai baris permintaan.
            if ($order->items->where('is_returned', false)->isNotEmpty()) {
                $paket = [];
                $paketSetKeys = [];
                $satuan = [];
                foreach ($order->items as $it) {
                    if ($it->is_returned) {
                        continue;
                    }
                    if ($it->source === 'paket') {
                        $name = $it->package_name ?? 'Paket';
                        $paket[$name] = ($paket[$name] ?? 0) + 1;
                        // Kumpulkan penanda set unit ini; jumlah set = penanda unik.
                        if ($key = $setKeyByStock[(int) $it->instrument_stock_id] ?? null) {
                            $paketSetKeys[$name][$key] = true;
                        }
                    } else {
                        $name = $it->instrumentStock?->instrument?->name ?? '—';
                        $satuan[$name] = ($satuan[$name] ?? 0) + 1;
                    }
                }
                // Angka paket SELALU jumlah set, tidak pernah jumlah unit:
                //  1. dari penanda set unit yang benar-benar masih dipinjam — ini yang
                //     tetap benar untuk order pinjam-alih (tidak punya baris permintaan)
                //     maupun pengembalian sebagian;
                //  2. cadangan: jumlah yang diminta di baris permintaan;
                //  3. jalan terakhir: 1 set, supaya tidak pernah memajang jumlah unit
                //     dengan satuan "set".
                foreach ($paket as $name => $unitQty) {
                    $sets = count($paketSetKeys[$name] ?? []);
                    $lines[] = [
                        'jenis' => 'Paket',
                        'name' => $name,
                        'qty' => $sets > 0 ? $sets : ($setsByPackage[$name] ?? 1),
                    ];
                }
                foreach ($satuan as $name => $qty) {
                    $lines[] = ['jenis' => 'Satuan', 'name' => $name, 'qty' => $qty];
                }
            } else {
                foreach ($order->requestItems as $line) {
                    if ($line->type === 'paket') {
                        $name = $line->catalog?->name ?? $line->package_name ?? 'Paket';
                        $lines[] = ['jenis' => 'Paket', 'name' => $name, 'qty' => (int) $line->quantity];
                    } else {
                        $lines[] = ['jenis' => 'Satuan', 'name' => $line->instrument?->name ?? '—', 'qty' => (int) $line->quantity];
                    }
                }
            }

            return [
                'order_code' => $order->code,
                'no_transaction' => $order->code_transaction,
                // Peminjam terakhir; kolom order dipakai bila order belum punya event
                // (mis. data lama), `created_by` sebagai jalan terakhir agar kolomnya
                // tidak pernah kosong di layar.
                'borrowed_by' => $latestBorrowerByOrder[$order->id] ?? $order->borrowed_by ?? $order->created_by,
                'order_date' => optional($order->order_date)->toDateString(),
                'order_time' => optional($order->created_at)->format('H:i'),
                'room_id' => $order->room?->id,
                'room_name' => $order->room?->name,
                'status' => $order->status,
                'lines' => $lines,
            ];
        })->values();

        return $this->success('Data papan monitoring berhasil diambil.', $rows);
    }

    /**
     * Peta `instrument_stock_id` → penanda SET unit itu, dipakai papan monitor untuk
     * menghitung jumlah set (bukan jumlah unit) pada baris paket.
     *
     * Penanda = `production_id|package_no` dari snapshot production_item: seluruh
     * unit dalam satu set berbagi `package_no` yang sama, dan `production_id` ikut
     * dibawa karena nomor itu hanya unik DI DALAM satu batch produksi.
     *
     * Unit tanpa `package_no` (data lama sebelum kolom itu ada) sengaja tidak
     * dimasukkan — pemanggil jatuh ke baris permintaan, bukan menghitung unit.
     *
     * @param  array<int,int>  $stockIds
     * @return array<int,string>
     */
    private function setKeyByStock(array $stockIds): array
    {
        if (empty($stockIds)) {
            return [];
        }

        return ProductionItem::whereIn('instrument_stock_id', $stockIds)
            ->whereNotNull('package_no')
            ->orderBy('id')
            ->get(['instrument_stock_id', 'production_id', 'package_no'])
            // Urut id ASC → batch terbaru menimpa yang lama, sama seperti pembacaan
            // nama/kode unit di tempat lain.
            ->mapWithKeys(fn ($pi) => [
                (int) $pi->instrument_stock_id => $pi->production_id.'|'.$pi->package_no,
            ])
            ->all();
    }
}
