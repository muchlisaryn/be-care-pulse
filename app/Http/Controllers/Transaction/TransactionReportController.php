<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Laporan Transaksi Instrumen — rekap peminjaman dengan satu baris per LABEL
 * KEMASAN (`packaging_item.barcode_no`) di tiap transaksi. Kolomnya: tanggal
 * transaksi, no invoice, nama instrumen/set, nomor barcode, nama peminjam + tanggal
 * peminjamannya, ruangan, identitas pasien (No. RM & nama), serta siapa yang
 * mengembalikan dan kapan.
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
 *
 * Nama baris ditentukan `production_item.source`: `paket` memakai `package_name`,
 * `satuan` memakai `name`. Keduanya tidak saling menggantikan.
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
        $rows = $this->groupByBarcode(
            $units,
            $this->barcodeMap($units),
            $this->returnedAtMap($units)
        );
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
                // Identitas pasien tujuan alat (traceability loop) — sering kosong pada
                // order yang belum sampai tahap distribusi.
                'o.medical_record_no',
                'o.patient_name',
                // Pengembalian: nama pengembali + tanggalnya. Tanggal ini hanya DATE;
                // jam persisnya diambil dari order_events (lihat returnedAtMap).
                'o.returned_by',
                'o.return_actual_date',
                'r.name as room_name',
                'pr.code as production_code',
                // Tanggal peminjaman = saat batch produksi unit ini dibuat. Tabel
                // `production` sengaja tidak punya kolom started_at (dibuang di
                // migration 2026_07_18_000008): batch dibuat & unit dikunci dalam satu
                // aksi, jadi `created_at` MEMANG waktu mulai produksinya.
                'pr.created_at as production_at',
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
     * Waktu pengembalian per transaksi, di-key `order_id`.
     *
     * `order.return_actual_date` hanya menyimpan TANGGAL, sedangkan event timeline
     * `dikembalikan` menyimpan momen persisnya — jadi jam pengembalian diambil dari
     * sana. Order lama yang tidak punya event tetap terlaporkan lewat tanggalnya
     * (lihat `returned_at` vs `return_date` di groupByBarcode).
     *
     * Urut id ASC lalu ditimpa, sehingga bila satu order sempat dikembalikan
     * bertahap (per unit) yang menang adalah event TERAKHIR — momen order itu
     * benar-benar tuntas kembali.
     *
     * `order_events` bersifat append-only dan tidak punya kolom soft delete, jadi
     * tidak ada filter `deleted_by` di sini.
     *
     * @param  Collection<int,object>  $units
     * @return array<int,string>
     */
    private function returnedAtMap(Collection $units): array
    {
        $orderIds = $units->pluck('order_id')->unique()->values()->all();

        if (empty($orderIds)) {
            return [];
        }

        $map = [];

        DB::table('order_events')
            ->whereIn('order_id', $orderIds)
            ->where('type', OrderEvent::TYPE_DIKEMBALIKAN)
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->get(['order_id', 'created_at'])
            ->each(function ($row) use (&$map) {
                $map[(int) $row->order_id] = $row->created_at;
            });

        return $map;
    }

    /**
     * Nama set per baris laporan, di-key `orderId|label`.
     *
     * Nama set diambil dari `production_item.package_name` anggota MANA PUN yang
     * terisi, bukan dari unit pertama saja. `package_name` tidak dijamin seragam
     * di seluruh anggota satu set — pada data lama sebagian unit bisa kosong, dan
     * kalau yang kosong itu kebetulan unit pertama (urutan `st.id`), nama setnya
     * hilang padahal anggota lain masih menyimpannya.
     *
     * Bernilai null bila SELURUH anggota memang tidak menyimpan nama set; barisnya
     * lalu tampil "—", bukan dipinjamkan nama instrumen.
     *
     * @param  Collection<int,object>  $units
     * @param  array<string,string>  $barcodes
     * @return array<string,string>
     */
    private function packageNameMap(Collection $units, array $barcodes): array
    {
        $map = [];

        foreach ($units as $unit) {
            if ($unit->source !== 'paket') {
                continue;
            }

            $packageName = trim((string) ($unit->package_name ?? ''));
            if ($packageName === '') {
                continue;
            }

            $barcode = $barcodes[$unit->production_code.'|'.$unit->instrument_stock_id] ?? null;
            $label = $barcode ?? 'tanpa-label#'.$unit->storage_id;
            // Anggota pertama yang terisi yang menang; sisanya tidak menimpa.
            $map[$unit->order_id.'|'.$label] ??= $packageName;
        }

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
     * @param  array<int,string>  $returnedAt
     * @return array<int,array<string,mixed>>
     */
    private function groupByBarcode(Collection $units, array $barcodes, array $returnedAt = []): array
    {
        $packageNames = $this->packageNameMap($units, $barcodes);
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
            // Nama set diambil dari peta (anggota mana pun yang `package_name`-nya
            // terisi), bukan dari unit pertama saja — lihat packageNameMap().
            $packageName = $packageNames[$orderId.'|'.$label] ?? null;

            $groups[$orderId][$label] = [
                // Kunci baris unik lintas transaksi — dipakai frontend sebagai React key.
                'key' => $orderId.'|'.$label,
                'order_id' => $orderId,
                'transaction_date' => $unit->order_date,
                // Nomor invoice transaksi (INV-...); null pada order yang belum diproses.
                'invoice_no' => $unit->code_transaction,
                'barcode_no' => $barcode,
                'type' => $isPaket ? 'paket' : 'satuan',
                // Nama mengikuti `source`, tanpa saling meminjam: `paket` SELALU dari
                // `package_name`, `satuan` SELALU dari `name`. Satu label paket memuat
                // banyak instrumen, jadi memakai `name` di situ berarti menampilkan
                // nama SALAH SATU isinya seolah-olah itu nama setnya. Lebih baik null
                // (tampil "—") daripada menyesatkan.
                'name' => $isPaket ? $packageName : $unit->item_name,
                'borrowed_by' => $unit->borrowed_by,
                // Tanggal peminjaman, diambil dari mulainya produksi batch ini.
                'borrowed_date' => $unit->production_at,
                'room' => $unit->room_name,
                'medical_record_no' => $unit->medical_record_no,
                'patient_name' => $unit->patient_name,
                'returned_by' => $unit->returned_by,
                'return_date' => $unit->return_actual_date,
                // Momen persis pengembalian; null pada order lama yang tidak punya
                // event timeline — frontend jatuh ke `return_date` bila null.
                'returned_at' => $returnedAt[$orderId] ?? null,
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
