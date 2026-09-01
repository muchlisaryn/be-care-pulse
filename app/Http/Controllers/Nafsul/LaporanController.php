<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Laporan modul Nafsul — dua sudut pandang atas data iuran yang sama.
 *
 *  1. `penerimaan()`  — per KUITANSI: uang yang masuk, potongan, dan jasa ketua
 *                       kelompok. Menjawab "berapa yang diterima kas".
 *  2. `perAnggota()`  — per RINCIAN: siapa membayar periode apa, dengan tarif
 *                       berapa. Menjawab "iuran siapa saja yang sudah masuk".
 *
 * Keduanya BUKAN pengulangan daftar transaksi yang sudah ada. Daftar itu alat
 * kerja harian — dipagi 25 baris, tanpa penjumlahan. Laporan ini selalu
 * mengembalikan `summary` yang dihitung atas SELURUH baris hasil saring, bukan
 * atas halaman yang kebetulan sedang tampil; angka rekap yang hanya menjumlah
 * satu halaman adalah angka yang salah, dan salahnya tidak terlihat.
 *
 * Tidak ada endpoint export terpisah: frontend meminta halaman besar
 * (`per_page`) dengan penyaring yang sama lalu merangkainya jadi .xlsx di
 * peramban — lihat `lib/excel.ts`. Satu sumber angka untuk layar dan berkas.
 *
 * Seperti controller Nafsul lain, responsnya JSON polos (bukan pembungkus
 * `success`/`error`) karena `lib/nafsul/api.ts` membaca body-nya langsung.
 */
class LaporanController extends Controller
{
    use HandlesTransactionRows;

    /**
     * Batas atas `per_page`.
     *
     * Angkanya dipilih untuk melayani export — satu berkas .xlsx sekali minta —
     * bukan untuk ditampilkan. Tanpa batas, `per_page=1000000` menarik seluruh
     * tabel ke memori PHP dan mematikan proses; dengan batas ini permintaan
     * seperti itu tetap dilayani, hanya terpotong.
     */
    private const MAX_PER_PAGE = 5000;

    /**
     * Dasar tanggal kuitansi — SAMA PERSIS dengan `TransaksiHeaderController::index()`
     * dan `DashboardController`.
     *
     * `date` (tanggal uang diterima) dengan `created_at` sebagai cadangan untuk
     * baris lama yang kolomnya belum terisi. Kalau laporan berangkat dari dasar
     * tanggal yang lain, angkanya tidak akan pernah cocok dengan daftar
     * transaksi yang dibuka petugas untuk memeriksanya.
     */
    private const TANGGAL_KUITANSI = 'DATE(COALESCE(`date`, created_at))';

    /**
     * GET /api/nafsul/laporan/penerimaan
     *
     * Query: `search` (no. kuitansi / nama ketua), `date_from`, `date_to`,
     * `transaction_type`, `payment_method`, `validation` (validated|unvalidated),
     * `page`, `per_page`.
     */
    public function penerimaan(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'transaction_type' => ['nullable', 'string', 'in:'.implode(',', TransactionHeader::TRANSACTION_TYPES)],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', TransactionHeader::PAYMENT_METHODS)],
            'validation' => ['nullable', 'string', 'in:validated,unvalidated'],
        ]);

        $data = $this->queryPenerimaan($request)
            ->withGroupLeaderName()
            ->withCount('transactions')
            ->orderByDesc('transaction_number')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $data->getCollection()->transform(fn (TransactionHeader $row) => [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'transaction_number' => $row->transaction_number,
            'date' => optional($row->date)->toDateString(),
            'transaction_type' => $row->transaction_type,
            // Pada kuitansi PRIBADI nilainya diabaikan frontend — yang tampil
            // "Pribadi", diturunkan dari `transaction_type`. Tetap dikirim agar
            // bentuk barisnya satu macam.
            'group_leader_name' => $row->group_leader_name,
            'transactions_count' => $row->transactions_count ?? 0,
            'total' => $row->total,
            'member_deduction' => $row->member_deduction,
            'group_leader_deduction' => $row->group_leader_deduction,
            'group_leader_fee' => $row->group_leader_fee,
            'payment' => $row->payment,
            'payment_method' => $row->payment_method,
            // `validation_at` null = belum divalidasi; tidak ada boolean
            // terpisah yang bisa menyimpang darinya.
            'validation_at' => optional($row->validation_at)->toDateTimeString(),
            'validation_by' => $row->validation_by,
        ]);

        // Query dirakit ULANG, bukan di-clone dari yang sudah dipaginasi:
        // `withGroupLeaderName()` & `withCount()` menyisipkan subquery per baris
        // yang tidak ada gunanya di dalam agregat, dan `paginate()` sudah
        // menempelkan limit/offset pada builder-nya.
        $rekap = $this->queryPenerimaan($request)
            ->selectRaw(implode(', ', [
                'COUNT(*) as receipts',
                'COALESCE(SUM(total), 0) as total',
                'COALESCE(SUM(member_deduction), 0) as member_deduction',
                'COALESCE(SUM(group_leader_deduction), 0) as group_leader_deduction',
                'COALESCE(SUM(group_leader_fee), 0) as group_leader_fee',
                'COALESCE(SUM(payment), 0) as payment',
            ]))
            ->first();

        return response()->json($data->toArray() + [
            'summary' => [
                'receipts' => (int) $rekap->receipts,
                'total' => $this->rupiah($rekap->total),
                'member_deduction' => $this->rupiah($rekap->member_deduction),
                'group_leader_deduction' => $this->rupiah($rekap->group_leader_deduction),
                'group_leader_fee' => $this->rupiah($rekap->group_leader_fee),
                'payment' => $this->rupiah($rekap->payment),
            ],
        ]);
    }

    /**
     * GET /api/nafsul/laporan/per-anggota
     *
     * Query: `search` (nama / no. anggota), `region_code`, `group_leader_code`,
     * `rate_code`, `period_from`, `period_to` (MM/YYYY), `date_from`, `date_to`
     * (tanggal kuitansi), `status` (paid|unpaid), `page`, `per_page`.
     */
    public function perAnggota(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'period_from' => ['nullable', 'string'],
            'period_to' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:paid,unpaid'],
        ]);

        $data = $this->queryPerAnggota($request)
            ->select([
                'transactions.id',
                'transactions.uuid',
                // `month` & `year` ikut diambil karena accessor `payment_period`
                // dirakit dari keduanya; tanpa itu periodenya selalu null.
                'transactions.month',
                'transactions.year',
                'transactions.amount',
                'transactions.discount',
                'm.member_number',
                'm.name as member_name',
                'r.name as region_name',
                'gl.name as group_leader_name',
                'rt.code as rate_code',
                'rt.name as rate_name',
                'h.transaction_number',
                'h.date as transaction_date',
                'h.payment_method',
                'h.validation_at',
            ])
            // Urut per anggota, baru kronologis di dalamnya — laporan ini dibaca
            // per orang, bukan per tanggal. `transactions.id` sebagai pemecah
            // seri agar urutannya tidak berubah antar halaman.
            ->orderBy('m.name')
            ->orderBy('transactions.year')
            ->orderBy('transactions.month')
            ->orderBy('transactions.id')
            ->paginate($this->perPage($request));

        $data->getCollection()->transform(fn (Transaction $row) => [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'member_number' => $row->member_number,
            'member_name' => $row->member_name,
            'region_name' => $row->region_name,
            'group_leader_name' => $row->group_leader_name,
            // Null untuk tarif SEKALI BAYAR yang memang tak berperiode.
            'payment_period' => $row->payment_period,
            'rate_code' => $row->rate_code,
            'rate_name' => $row->rate_name,
            'amount' => $row->amount,
            'discount' => $row->discount,
            'total' => $row->total,
            // Null = rincian ini masih tagihan, belum masuk kuitansi mana pun.
            'transaction_number' => $row->transaction_number,
            'transaction_date' => $row->transaction_date,
            'payment_method' => $row->payment_method,
            'validation_at' => $row->validation_at,
        ]);

        $rekap = $this->queryPerAnggota($request)
            ->selectRaw(implode(', ', [
                'COUNT(*) as rows_count',
                // Satu anggota bisa punya banyak baris (beberapa periode); yang
                // ditanyakan laporan adalah berapa ORANG, bukan berapa baris.
                'COUNT(DISTINCT transactions.member_id) as members',
                'COALESCE(SUM(transactions.amount), 0) as amount',
                'COALESCE(SUM(transactions.discount), 0) as discount',
            ]))
            ->first();

        return response()->json($data->toArray() + [
            'summary' => [
                'rows' => (int) $rekap->rows_count,
                'members' => (int) $rekap->members,
                'amount' => $this->rupiah($rekap->amount),
                'discount' => $this->rupiah($rekap->discount),
                // Dihitung dari selisihnya, bukan SUM(amount - discount):
                // keduanya sama secara aritmetika, dan bentuk ini tidak bisa
                // melenceng dari dua angka yang ditampilkan di sebelahnya.
                'total' => $this->rupiah((float) $rekap->amount - (float) $rekap->discount),
            ],
        ]);
    }

    /**
     * Kuitansi yang memenuhi penyaring — tanpa kolom tambahan, urutan, maupun
     * paginasi, supaya bisa dipakai baik untuk daftar maupun untuk agregat.
     */
    private function queryPenerimaan(Request $request): Builder
    {
        $query = TransactionHeader::query();

        if ($search = $request->query('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                    // Nama ketua kelompok pemilik kuitansi. Petugas mencari
                    // "setoran Pak Anu", bukan menghafal nomor kuitansinya.
                    ->orWhereExists(function (BuilderContract $sub) use ($search) {
                        $sub->from('transactions as tx')
                            ->join('members as m', 'm.id', '=', 'tx.member_id')
                            ->join('group_leaders as gl', 'gl.id', '=', 'm.group_leader_id')
                            ->whereColumn('tx.transaction_header_id', 'transaction_headers.id')
                            ->whereNull('tx.deleted_by')
                            ->whereNull('m.deleted_by')
                            ->whereNull('gl.deleted_by')
                            ->where('gl.name', 'like', "%{$search}%");
                    });
            });
        }

        if ($jenis = $request->query('transaction_type')) {
            $query->where('transaction_type', $jenis);
        }

        if ($metode = $request->query('payment_method')) {
            $query->where('payment_method', $metode);
        }

        if ($dari = $request->query('date_from')) {
            $query->whereRaw(self::TANGGAL_KUITANSI.' >= ?', [$dari]);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereRaw(self::TANGGAL_KUITANSI.' <= ?', [$sampai]);
        }

        if ($validasi = $request->query('validation')) {
            $validasi === 'validated'
                ? $query->whereNotNull('validation_at')
                : $query->whereNull('validation_at');
        }

        return $query;
    }

    /**
     * Rincian iuran yang memenuhi penyaring, sudah di-join ke anggota, wilayah,
     * ketua kelompok, tarif, dan kuitansinya.
     *
     * Dipakai join, bukan `with()`: laporan ini menyaring DAN mengurutkan
     * berdasarkan kolom tabel tetangga (nama anggota, nama wilayah), dan itu
     * tidak bisa dilakukan pada relasi yang baru dimuat setelah barisnya
     * terambil.
     *
     * Global scope `active` DILEPAS lalu syaratnya ditulis ulang berkualifikasi
     * tabel: scope itu menulis `deleted_by` tanpa nama tabel, sehingga menjadi
     * ambigu begitu tabel lain yang juga punya kolom itu ikut di-join.
     */
    private function queryPerAnggota(Request $request): Builder
    {
        $query = Transaction::query()
            ->withoutGlobalScope('active')
            ->join('members as m', 'm.id', '=', 'transactions.member_id')
            ->leftJoin('regions as r', 'r.id', '=', 'm.region_id')
            ->leftJoin('group_leaders as gl', 'gl.id', '=', 'm.group_leader_id')
            ->join('rates as rt', 'rt.id', '=', 'transactions.rate_id')
            // LEFT join: rincian yang belum dibayar memang belum punya kuitansi,
            // dan justru baris itulah yang dicari saat menelusuri tagihan.
            // Syarat "kuitansinya belum dihapus" ditaruh di ON, bukan di WHERE —
            // di WHERE ia membuang baris tanpa kuitansi sama sekali, karena
            // perbandingan apa pun terhadap NULL tidak pernah bernilai true.
            ->leftJoin('transaction_headers as h', function ($join) {
                $join->on('h.id', '=', 'transactions.transaction_header_id')
                    ->whereNull('h.deleted_by');
            })
            ->whereNull('transactions.deleted_by')
            ->whereNull('m.deleted_by');

        if ($search = $request->query('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('m.name', 'like', "%{$search}%")
                    ->orWhere('m.member_number', 'like', "%{$search}%");
            });
        }

        // Wilayah & ketua kelompok disaring lewat KODE, bukan id: kode itulah
        // yang dipakai kontrak API master Nafsul (`kode`, `noketua`), sehingga
        // frontend bisa mengoper nilai dropdown apa adanya.
        if ($wilayah = $request->query('region_code')) {
            $query->where('r.code', $wilayah);
        }

        if ($ketua = $request->query('group_leader_code')) {
            $query->where('gl.code', $ketua);
        }

        // Tarif juga disaring lewat KODE, bukan id: kontrak API master tarif
        // hanya mengeluarkan `kode`/`nama`, jadi id-nya memang tidak pernah
        // sampai ke frontend untuk bisa dikirim balik.
        if ($tarif = $request->query('rate_code')) {
            $query->where('rt.code', $tarif);
        }

        if ($dari = $request->query('period_from')) {
            $this->filterRentangPeriode($query, $this->pecahPeriode($dari, 'period_from'), '>=', 'transactions');
        }

        if ($sampai = $request->query('period_to')) {
            $this->filterRentangPeriode($query, $this->pecahPeriode($sampai, 'period_to'), '<=', 'transactions');
        }

        // Rentang TANGGAL KUITANSI — berbeda dari rentang periode di atas.
        // Periode adalah bulan iuran yang dibayar, tanggal kuitansi adalah kapan
        // uangnya diterima; iuran Januari bisa saja dibayar pada Maret.
        //
        // Menyaringnya otomatis membuang rincian yang belum punya kuitansi:
        // baris itu memang belum diterima uangnya, jadi ia tidak berada di dalam
        // rentang penerimaan mana pun.
        if ($dari = $request->query('date_from')) {
            $query->whereRaw('DATE(COALESCE(h.`date`, h.created_at)) >= ?', [$dari]);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereRaw('DATE(COALESCE(h.`date`, h.created_at)) <= ?', [$sampai]);
        }

        if ($status = $request->query('status')) {
            $status === 'paid'
                ? $query->whereNotNull('h.id')
                : $query->whereNull('h.id');
        }

        return $query;
    }

    /**
     * Jumlah baris per halaman, dibatasi `MAX_PER_PAGE`.
     *
     * Bawaannya 25, sama dengan daftar transaksi — laporan yang dibuka pertama
     * kali menampilkan sebanyak yang sudah biasa dilihat petugas.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), self::MAX_PER_PAGE);
    }

    /**
     * Angka rupiah sebagai string dua desimal — bentuk yang sama dengan kolom
     * DECIMAL yang keluar dari Eloquent.
     *
     * Dijadikan string, bukan float, supaya frontend tidak perlu membedakan
     * angka dari baris (string) dan angka dari rekap (float) saat memformatnya.
     */
    private function rupiah(mixed $nilai): string
    {
        return number_format((float) $nilai, 2, '.', '');
    }
}
