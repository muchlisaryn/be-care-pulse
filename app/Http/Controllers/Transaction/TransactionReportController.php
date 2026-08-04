<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Laporan Transaksi Instrumen — rekap peminjaman dengan satu baris per LABEL
 * KEMASAN (`packaging_item.barcode_no`) di tiap transaksi. Kolomnya: tanggal
 * transaksi, no invoice, nama instrumen/set, nomor barcode, nama peminjam, ruangan.
 *
 * Dibangun terpisah dari ReportController::transaksi (laporan lama) dan tidak
 * memakai satu pun helper-nya — laporan ini punya rantai datanya sendiri:
 *
 *   order → instrument_storages → production_item  (nama instrumen / nama set)
 *   production → washing → packaging → packaging_item  (nomor barcode label)
 *
 * `production_item` dipakai sebagai sumber nama karena isinya SNAPSHOT identitas
 * unit saat batch dikunci, jadi laporan lama tidak ikut berubah bila master
 * instrumen di-rename. Nomor barcode dicocokkan per SIKLUS (kode produksi + unit),
 * bukan "label terakhir unit": satu unit fisik bisa melewati pipeline berkali-kali
 * dan tiap siklus punya labelnya sendiri.
 */
class TransactionReportController extends Controller
{
    /**
     * Status order yang dilaporkan bila `?status=` tidak dikirim. Hanya transaksi
     * yang sudah tuntas yang masuk laporan.
     */
    private const DEFAULT_STATUS = Order::STATUS_DIKEMBALIKAN;

    /** Batas `?per_page` — dinaikkan frontend saat export ke Excel/CSV. */
    private const MAX_PER_PAGE = 2000;

    /** Nilai `?type` yang diterima — sama dengan `production_item.source`. */
    private const TYPES = ['paket', 'satuan'];

    /**
     * GET /api/master/reports/transaksi-instrumen
     *
     * Filter: ?search (nomor barcode ATAU nama instrumen/set), ?room_id, ?type
     * (paket/satuan), ?status, ?date_from & ?date_to (tanggal transaksi).
     * ?per_page dipakai saat export.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'type' => ['nullable', Rule::in(self::TYPES)],
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'room_id' => 'nullable|integer|exists:rooms,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:1',
        ]);

        $perPage = min((int) ($request->per_page ?: 20), self::MAX_PER_PAGE);
        $page = max((int) ($request->page ?: 1), 1);

        $units = $this->units($request);
        $rows = $this->groupByBarcode($units, $this->barcodeMap($units));
        $rows = $this->applySearch($rows, $request->search);

        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page
        );

        return $this->success('Laporan transaksi instrumen berhasil diambil.', $paginator);
    }

    /**
     * Seluruh unit yang dipinjam pada transaksi yang lolos filter — satu baris per
     * unit fisik, belum dikelompokkan per label.
     *
     * Query builder mentah (bukan Eloquent) karena butuh JOIN empat tabel; sebagai
     * gantinya `deleted_by` tiap tabel dikualifikasi manual — global scope `active`
     * dari HasAuditColumns tidak ikut pada builder raw. Tabel `production` sengaja
     * tidak difilter: tabel itu memang tidak punya kolom soft delete.
     *
     * `rooms` di-leftJoin tanpa filter `deleted_by` supaya nama ruangan yang sudah
     * dihapus admin tetap terbaca di transaksi lama.
     *
     * @return Collection<int,object>
     */
    private function units(Request $request): Collection
    {
        return DB::table('instrument_storages as st')
            ->join('production_item as pi', 'pi.id', '=', 'st.production_item_id')
            ->join('production as pr', 'pr.id', '=', 'pi.production_id')
            // `order` reserved keyword SQL — alias wajib, grammar Laravel yang
            // memberi backtick-nya.
            ->join('order as o', 'o.id', '=', 'st.order_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'o.room_id')
            ->whereNull('st.deleted_by')
            ->whereNull('pi.deleted_by')
            ->whereNull('o.deleted_by')
            ->where('o.status', $request->status ?: self::DEFAULT_STATUS)
            ->when($request->room_id, fn ($q, $id) => $q->where('o.room_id', $id))
            // Jenis disaring di tingkat unit: seluruh unit di bawah satu label pasti
            // berasal dari bungkus yang sama, jadi `source`-nya seragam per baris.
            ->when($request->type, fn ($q, $t) => $q->where('pi.source', $t))
            ->when($request->date_from, fn ($q, $d) => $q->whereDate('o.order_date', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->whereDate('o.order_date', '<=', $d))
            ->orderByDesc('o.order_date')
            // Tie-break wajib: `order_date` hanya DATE, banyak transaksi berbagi satu
            // hari — tanpa ini urutan barisnya bisa bergeser antar halaman.
            ->orderByDesc('o.id')
            ->orderBy('st.id')
            ->get([
                'st.id as storage_id',
                'st.instrument_stock_id',
                'o.id as order_id',
                'o.order_date',
                'o.code_transaction',
                'o.borrowed_by',
                'r.name as room_name',
                'pr.code as production_code',
                'pi.name as item_name',
                'pi.source',
                'pi.package_name',
            ]);
    }

    /**
     * Peta nomor label tiap unit, di-key `productionCode|instrumentStockId`.
     *
     * Rantainya `production.code` → `washing.production_code` → `packaging.washing_code`
     * → `packaging_item`. Label yang di-void (`disabled`) diabaikan; urut id ASC
     * supaya bila satu unit dikemas ulang pada siklus yang sama (RPK), label
     * TERBARU yang menang.
     *
     * @param  Collection<int,object>  $units
     * @return array<string,string>
     */
    private function barcodeMap(Collection $units): array
    {
        $productionCodes = $units->pluck('production_code')->filter()->unique()->values()->all();
        $stockIds = $units->pluck('instrument_stock_id')->filter()->unique()->values()->all();

        if (empty($productionCodes) || empty($stockIds)) {
            return [];
        }

        $map = [];

        DB::table('packaging_item as pit')
            ->join('packaging as pk', 'pk.id', '=', 'pit.packaging_id')
            ->join('washing as w', 'w.code', '=', 'pk.washing_code')
            ->whereIn('w.production_code', $productionCodes)
            ->whereIn('pit.instrument_stock_id', $stockIds)
            ->where('pit.disabled', false)
            ->whereNotNull('pit.barcode_no')
            ->whereNull('pit.deleted_by')
            ->whereNull('pk.deleted_by')
            ->whereNull('w.deleted_by')
            ->orderBy('pit.id')
            ->get(['w.production_code', 'pit.instrument_stock_id', 'pit.barcode_no'])
            ->each(function ($row) use (&$map) {
                $map[$row->production_code.'|'.$row->instrument_stock_id] = $row->barcode_no;
            });

        return $map;
    }

    /**
     * Kelompokkan unit menjadi baris laporan: satu label kemasan = satu bungkus
     * fisik = SATU BARIS. Unit dalam satu set berbagi satu `barcode_no` sehingga
     * lebur jadi satu baris bernama nama set-nya.
     *
     * Unit yang belum punya label TIDAK pernah digabung (kuncinya memakai id baris
     * gudang) — kalau digabung, dua bungkus berbeda akan tampak sebagai satu baris.
     *
     * Baris dikumpulkan per transaksi lalu diurutkan per nomor label di dalamnya,
     * supaya mudah dicocokkan dengan bungkus fisik. Urutan antar transaksi tetap
     * mengikuti urutan query (tanggal terbaru dulu) karena kunci luar ditambahkan
     * sesuai kemunculannya.
     *
     * @param  Collection<int,object>  $units
     * @param  array<string,string>  $barcodes
     * @return array<int,array<string,mixed>>
     */
    private function groupByBarcode(Collection $units, array $barcodes): array
    {
        $groups = [];

        foreach ($units as $unit) {
            $barcode = $barcodes[$unit->production_code.'|'.$unit->instrument_stock_id] ?? null;
            $label = $barcode ?? 'tanpa-label#'.$unit->storage_id;
            $orderId = (int) $unit->order_id;

            $groups[$orderId] ??= [];

            if (isset($groups[$orderId][$label])) {
                continue;
            }

            $isPaket = $unit->source === 'paket';

            $groups[$orderId][$label] = [
                // Kunci baris unik lintas transaksi — dipakai frontend sebagai React key.
                'key' => $orderId.'|'.$label,
                'order_id' => $orderId,
                'transaction_date' => $unit->order_date,
                // Nomor invoice transaksi (INV-...); null pada order yang belum diproses.
                'invoice_no' => $unit->code_transaction,
                'barcode_no' => $barcode,
                'type' => $isPaket ? 'paket' : 'satuan',
                // Satu label paket memuat banyak instrumen — yang ditampilkan HARUS
                // nama setnya, bukan nama salah satu isinya.
                'name' => $isPaket ? ($unit->package_name ?: $unit->item_name) : $unit->item_name,
                'borrowed_by' => $unit->borrowed_by,
                'room' => $unit->room_name,
            ];
        }

        $rows = [];
        foreach ($groups as $labels) {
            ksort($labels);
            foreach ($labels as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Filter `?search`: cocokkan ke NOMOR BARCODE atau NAMA instrumen/set.
     *
     * Sengaja dijalankan setelah pengelompokan, bukan sebagai WHERE: kedua kolom
     * itu baru ada setelah baris disusun (barcode dari peta siklus, nama dipilih
     * antara nama set vs nama instrumen), jadi menyaring di SQL berisiko
     * memberi hasil yang berbeda dari yang tampil di tabel.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function applySearch(array $rows, ?string $search): array
    {
        $needle = mb_strtolower(trim((string) $search));

        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($row) => str_contains(mb_strtolower((string) $row['barcode_no']), $needle)
            || str_contains(mb_strtolower((string) $row['name']), $needle)));
    }
}
