<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\PackagingItem;
use App\Models\ProductionItem;
use App\Models\Sterilization;
use App\Models\SterilizationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Nilai indikator biologi (pembanding & uji) — sama dengan yang diterima
     * SterilizationPipelineController::validateResult saat hasil batch divalidasi.
     */
    private const BIO_INDICATOR_VALUES = ['Negatif', 'Positif'];

    /**
     * Laporan CSSD Per Alat: satu baris per LABEL KEMASAN (`packaging_item.barcode_no`)
     * di setiap batch sterilisasi. Satu label = satu bungkus fisik, jadi seluruh unit
     * yang dikemas bersama lebur menjadi satu baris yang bisa di-expand.
     *
     * Sumber data = SterilizationItem → Sterilization + InstrumentStock. Nama alat/paket
     * HANYA dari SNAPSHOT `production_item` supaya laporan lama tidak berubah saat
     * master instrumen di-rename — master `instruments` dan `order_item` tidak lagi
     * dipakai sebagai cadangan. Snapshot dicari per SIKLUS, lihat productionItemByCycle().
     *
     * Pengelompokannya sengaja TIDAK lagi memakai asal "paket"/"satuan" dari
     * order_item: label kemasan adalah yang benar-benar dipegang petugas, dan satu
     * order paket bisa terbagi ke beberapa bungkus (atau sebaliknya). Unit yang belum
     * punya label tidak pernah digabung — kalau digabung, dua bungkus berbeda akan
     * tampak sebagai satu baris.
     *
     * Filter: ?search (nama/kode alat), ?status, ?method, ?machine, ?result
     * (berhasil/gagal), ?chemical_indicator (nomor lot), ?bio_indicator_control &
     * ?bio_indicator_test (Negatif/Positif), ?date_from, ?date_to (tanggal
     * sterilisasi). ?per_page boleh dipakai untuk export (default 20, maks 2000).
     *
     * Unit yang GAGAL steril tetap dilaporkan (barisnya ditandai `failed`), bukan
     * disembunyikan — laporan ini juga dipakai menelusuri kegagalan.
     */
    public function cssdPerItem(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(Sterilization::STATUSES)],
            'method' => ['nullable', Rule::in(Sterilization::METHODS)],
            'machine' => 'nullable|string|max:255',
            'result' => ['nullable', Rule::in([Sterilization::RESULT_BERHASIL, Sterilization::RESULT_GAGAL])],
            // Indikator hasil validasi batch. `chemical_indicator` berisi nomor lot
            // (teks bebas) sehingga dicocokkan persis dengan nilai yang dipilih dari
            // daftar yang memang ada di data — bukan pencarian sebagian.
            'chemical_indicator' => 'nullable|string|max:100',
            'bio_indicator_control' => ['nullable', Rule::in(self::BIO_INDICATOR_VALUES)],
            'bio_indicator_test' => ['nullable', Rule::in(self::BIO_INDICATOR_VALUES)],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1',
        ]);

        $perPage = min((int) ($request->per_page ?: 20), 2000);
        $page = max((int) ($request->page ?: 1), 1);

        // Ambil seluruh unit yang cocok (laporan dibatasi filter), lalu kelompokkan
        // per PAKET (satu baris per paket per batch) di sisi server — agar grup tidak
        // terpotong antar halaman. Instrumen satuan tetap satu baris per unit.
        $items = SterilizationItem::query()
            ->whereHas('sterilization', function ($q) use ($request) {
                $q->when($request->status, fn ($q, $s) => $q->where('status', $s))
                    ->when($request->method, fn ($q, $m) => $q->where('method', $m))
                    ->when($request->machine, fn ($q, $m) => $q->where('machine', $m))
                    ->when($request->chemical_indicator, fn ($q, $v) => $q->where('chemical_indicator', $v))
                    ->when($request->bio_indicator_control, fn ($q, $v) => $q->where('bio_indicator_control', $v))
                    ->when($request->bio_indicator_test, fn ($q, $v) => $q->where('bio_indicator_test', $v))
                    ->when($request->date_from, fn ($q, $d) => $q->whereDate('sterilized_at', '>=', $d))
                    ->when($request->date_to, fn ($q, $d) => $q->whereDate('sterilized_at', '<=', $d));
            })
            // Berhasil / gagal dibaca dari `disabled` pada barisnya sendiri: unit yang
            // gagal steril di-void saat validasi batch (lihat
            // SterilizationPipelineController::validate) — recordnya tetap tersimpan.
            ->when(
                $request->result,
                fn ($q, $r) => $q->where('disabled', $r === Sterilization::RESULT_GAGAL)
            )
            ->when($request->search, fn ($q, $s) => $q->whereHas('instrumentStock', function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('instrument', fn ($q) => $q->where('name', 'like', "%{$s}%"));
            }))
            ->with(['sterilization', 'instrumentStock.instrument', 'instrumentStock.condition'])
            ->latest()
            ->get();

        $stockIds = $items->pluck('instrument_stock_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        // Cadangan untuk unit yang barisnya belum menyimpan label: snapshot TERBARU
        // unit itu (keyBy pada urutan id ASC → entri terakhir menang).
        $prodByStock = ProductionItem::whereIn('instrument_stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            ->keyBy('instrument_stock_id');

        $barcodeByStock = $this->barcodeNoByStock($stockIds);

        // Snapshot production_item PER SIKLUS — lihat productionItemByCycle().
        $prodByCycle = $this->productionItemByCycle(
            $items->pluck('packaging_barcode')
                ->filter()
                ->merge(array_values($barcodeByStock))
                ->unique()
                ->values()
                ->all(),
            $stockIds
        );

        // Nama baris dihitung setelah seluruh anggota terkumpul: `package_name` tidak
        // dijamin terisi di semua anggota satu bungkus, jadi tidak boleh hanya
        // mengandalkan unit yang kebetulan pertama masuk.
        $rowNames = [];

        $groups = [];
        foreach ($items as $item) {
            $batch = $item->sterilization;
            $stock = $item->instrumentStock;
            // Label yang unit ini BENAR-BENAR bawa saat batch itu disterilkan —
            // tersimpan di barisnya sendiri, jadi laporan lama tidak ikut bergeser
            // saat unitnya dikemas ulang di siklus berikutnya. Baris lama yang belum
            // punya kolom itu jatuh ke label terbaru unitnya.
            $barcode = $item->packaging_barcode ?? $barcodeByStock[(int) $item->instrument_stock_id] ?? null;

            // Snapshot siklus batch ini; unit tanpa label jatuh ke snapshot terbaru.
            $prod = $prodByCycle[$barcode.'|'.$item->instrument_stock_id]
                ?? $prodByStock->get($item->instrument_stock_id);

            $unit = [
                'id' => $item->id,
                // Nama unit SELALU `production_item.name` — nama instrumennya sendiri,
                // bukan nama paket, dan bukan master `instruments` yang bisa di-rename.
                'name' => $prod?->name,
                'unit_code' => $stock?->code,
                'result' => $item->result,
                // Gagal steril — sumbernya kolom `disabled` baris ini. Unit gagal TETAP
                // dilaporkan; validasi batch hanya menandainya, tidak menghapusnya.
                'failed' => (bool) $item->disabled,
            ];

            // Satu label = satu bungkus fisik pada satu batch. Unit tanpa label
            // dikunci per baris sterilisasi agar tidak pernah lebur dengan unit lain.
            $key = $barcode === null
                ? 'nolabel|'.$item->id
                : 'label|'.$batch?->id.'|'.$barcode;

            // Nama baris mengikuti `source`: paket → `package_name`, satuan → `name`.
            // Keduanya dari production_item dan tidak saling menggantikan — memakai
            // nama instrumen pada baris paket berarti menampilkan salah satu isinya
            // seolah-olah nama setnya.
            $candidate = $prod?->source === 'paket' ? $prod?->package_name : $prod?->name;
            if ($candidate !== null && trim((string) $candidate) !== '') {
                $rowNames[$key] ??= $candidate;
            }

            $groups[$key] ??= [
                'key' => $key,
                'barcode_no' => $barcode,
                // Diisi setelah seluruh anggota terkumpul (lihat $rowNames).
                'name' => null,
                'batch_code' => $batch?->code,
                'method' => $batch?->method,
                'machine' => $batch?->machine,
                'cycle_number' => $batch?->cycle_number,
                'temperature' => $batch?->temperature,
                'duration_minutes' => $batch?->duration_minutes,
                'operator' => $batch?->operator,
                'sterilized_at' => $batch?->sterilized_at,
                // Hasil validasi batch: indikator kimia + indikator biologi
                // (pembanding/kontrol & uji). Bernilai null pada batch yang belum
                // divalidasi.
                'chemical_indicator' => $batch?->chemical_indicator,
                'bio_indicator_control' => $batch?->bio_indicator_control,
                'bio_indicator_test' => $batch?->bio_indicator_test,
                'expiry_date' => $batch?->expiry_date,
                'qty' => 0,
                // Satu bungkus bisa berisi campuran hasil (mis. satu unit gagal, sisanya
                // lulus); baris ditandai gagal bila ADA unit yang gagal — supaya
                // bungkus bermasalah tidak pernah terlihat aman.
                'failed' => false,
                'units' => [],
            ];
            $groups[$key]['qty']++;
            $groups[$key]['failed'] = $groups[$key]['failed'] || $unit['failed'];
            $groups[$key]['units'][] = $unit;
        }

        // Baris berisi satu unit: kode unitnya dipakai sebagai identitas baris. Baris
        // gabungan tidak punya satu kode unit — kodenya ada di detail tiap unit.
        foreach ($groups as $key => $group) {
            $groups[$key]['unit_code'] = $group['qty'] === 1 ? $group['units'][0]['unit_code'] : null;
            // null bila tidak satu pun anggota menyimpan namanya — FE menampilkan "—".
            $groups[$key]['name'] = $rowNames[$key] ?? null;
        }

        $all = array_values($groups);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator($slice, count($all), $perPage, $page);

        return $this->success('Laporan CSSD per alat berhasil diambil.', $paginator);
    }

    /**
     * Pilihan filter MESIN untuk laporan CSSD per alat: daftar mesin yang benar-benar
     * pernah dipakai pada batch sterilisasi.
     *
     * Sengaja TIDAK diambil dari master `sterilizer_machines`: kolom
     * `sterilizations.machine` menyimpan NAMA mesin sebagai teks (snapshot saat batch
     * dijalankan), jadi mesin yang sudah dinonaktifkan atau di-rename di master tidak
     * akan cocok — padahal batch lamanya masih ada di laporan. Diambil dari datanya
     * sendiri, pilihannya selalu sama dengan isi laporan.
     */
    public function cssdMachines(): JsonResponse
    {
        $machines = Sterilization::query()
            ->whereNotNull('machine')
            ->where('machine', '!=', '')
            ->distinct()
            ->orderBy('machine')
            ->pluck('machine')
            ->values();

        return $this->success('Daftar mesin sterilisasi berhasil diambil.', $machines);
    }

    /**
     * Pilihan filter INDIKATOR KIMIA untuk laporan CSSD per alat: nomor lot yang
     * benar-benar pernah tercatat pada batch sterilisasi.
     *
     * Sama alasannya dengan cssdMachines: kolomnya teks bebas (nomor lot diketik
     * petugas saat validasi batch), jadi tidak ada master yang bisa dijadikan
     * sumber pilihan. Diambil dari datanya sendiri supaya pilihan di dropdown
     * selalu sama dengan isi laporan — tidak ada opsi yang hasilnya kosong.
     */
    public function cssdChemicalIndicators(): JsonResponse
    {
        $indicators = Sterilization::query()
            ->whereNotNull('chemical_indicator')
            ->where('chemical_indicator', '!=', '')
            ->distinct()
            ->orderBy('chemical_indicator')
            ->pluck('chemical_indicator')
            ->values();

        return $this->success('Daftar indikator kimia berhasil diambil.', $indicators);
    }

    /**
     * Snapshot `production_item` yang unit ini bawa PADA SIKLUS batch tersebut,
     * di-key `barcodeNo|instrumentStockId`.
     *
     * Sebelumnya nama diambil dari `ProductionItem::keyBy('instrument_stock_id')`,
     * yang hanya menyisakan SATU snapshot per unit — yang terbaru. Satu unit fisik
     * melewati pipeline berkali-kali, jadi laporan batch lama ikut menampilkan nama
     * dari batch terbaru; persis hal yang seharusnya dicegah oleh snapshot.
     *
     * Rantainya: `packaging_item.barcode_no` → `packaging` → `washing.production_code`
     * → `production` → `production_item` (batch + unit). Label yang sudah di-void
     * (`disabled`) SENGAJA tidak disaring: unit yang gagal steril labelnya di-void
     * dan dikemas ulang, tapi baris laporannya tetap merujuk siklus yang lama.
     *
     * @param  array<int,string>  $barcodes
     * @param  array<int,int>  $stockIds
     * @return array<string,object>
     */
    private function productionItemByCycle(array $barcodes, array $stockIds): array
    {
        if (empty($barcodes) || empty($stockIds)) {
            return [];
        }

        $map = [];

        DB::table('packaging_item as pit')
            ->join('packaging as pk', 'pk.id', '=', 'pit.packaging_id')
            ->join('washing as w', 'w.code', '=', 'pk.washing_code')
            ->join('production as pr', 'pr.code', '=', 'w.production_code')
            ->join('production_item as pi', function ($join) {
                $join->on('pi.production_id', '=', 'pr.id')
                    ->on('pi.instrument_stock_id', '=', 'pit.instrument_stock_id');
            })
            ->whereIn('pit.barcode_no', $barcodes)
            ->whereIn('pit.instrument_stock_id', $stockIds)
            // Query builder mentah tidak ikut global scope `active`, jadi soft delete
            // tiap tabel dikualifikasi manual. `production` memang tanpa kolom itu.
            ->whereNull('pit.deleted_by')
            ->whereNull('pk.deleted_by')
            ->whereNull('w.deleted_by')
            ->whereNull('pi.deleted_by')
            ->orderBy('pi.id')
            ->get([
                'pit.barcode_no',
                'pit.instrument_stock_id',
                'pi.name',
                'pi.source',
                'pi.package_name',
            ])
            ->each(function ($row) use (&$map) {
                $map[$row->barcode_no.'|'.$row->instrument_stock_id] = $row;
            });

        return $map;
    }

    /**
     * Nomor label kemasan (`packaging_item.barcode_no`) TERBARU tiap unit, di-key oleh
     * instrument_stock_id. Label yang sudah di-void (`disabled`) diabaikan; satu unit
     * bisa punya beberapa label lintas siklus, jadi yang diambil paling akhir.
     *
     * Aturannya sama dengan MonitoringController::barcodeNoByStock — kalau salah satu
     * berubah, ubah juga yang lain agar label di laporan & monitoring tidak berbeda.
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
}
