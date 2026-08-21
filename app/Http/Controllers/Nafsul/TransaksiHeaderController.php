<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Header transaksi iuran Nafsul — satu baris = satu kuitansi pembayaran.
 *
 * Rincian iurannya ada di `TransaksiController` (`/nafsul/transaksi`); satu
 * header bisa menaungi banyak rincian.
 *
 * Nama kolom database dan nama field API sama-sama Inggris, dan responsnya JSON
 * polos — sama dengan `TransaksiController`.
 */
class TransaksiHeaderController extends Controller
{
    use HandlesTransactionRows;

    public function index(Request $request)
    {
        $query = TransactionHeader::query()->withCount('transactions');

        if ($search = $request->query('search')) {
            $query->where('transaction_number', 'like', "%{$search}%");
        }

        if ($metode = $request->query('payment_method')) {
            $query->where('payment_method', $metode);
        }

        if ($jenis = $request->query('transaction_type')) {
            $query->where('transaction_type', $jenis);
        }

        // Filter tanggal transaksi (bukan periode iuran — itu milik rincian).
        if ($dari = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dari);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $sampai);
        }

        // Nomor transaksi urut kronologis, jadi pengurutannya cukup dari situ.
        // `id` sebagai pemecah seri agar urutannya tidak berubah antar halaman.
        $query->orderByDesc('transaction_number')->orderByDesc('id');

        $data = $query->paginate($request->integer('per_page', 25));

        $data->getCollection()->transform(fn ($row) => $this->transform($row));

        return response()->json($data);
    }

    /**
     * Simpan satu kuitansi beserta seluruh rinciannya sekaligus.
     *
     * Rincian boleh memuat lebih dari satu anggota — satu kuitansi bisa
     * menampung setoran beberapa anggota dari kelompok yang sama.
     *
     * Seluruhnya dibungkus satu transaksi database: kalau ada satu baris
     * rincian yang ditolak, header-nya ikut dibatalkan. Tanpa itu, kegagalan di
     * tengah menyisakan kuitansi kosong yang nomornya sudah terpakai.
     */
    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $baris = $this->validateRows($request);

        return DB::transaction(function () use ($data, $baris) {
            // Nomor dibuat di sini bila tidak dikirim klien — sama seperti nomor
            // anggota, yang boleh diisi manual untuk mencatat kuitansi lama.
            if (empty($data['transaction_number'])) {
                $data['transaction_number'] = TransactionHeader::generateNumber();
            }

            // Total selalu mengikuti jumlah rinciannya, bukan angka kiriman
            // klien. Kalau keduanya boleh berbeda, header dan rincian bisa
            // berselisih tanpa ada yang tahu mana yang benar.
            if ($baris !== []) {
                $data['total'] = array_sum(array_map(fn ($b) => $this->totalBaris($b), $baris));
            }

            $this->periksaPotongan($data);

            $header = TransactionHeader::create($data);

            foreach ($baris as $b) {
                $header->transactions()->create($b);
            }

            return response()->json(
                $this->transform($header->loadCount('transactions'), true),
                201
            );
        });
    }

    /**
     * Validasi & normalkan rincian yang ikut dikirim bersama header.
     *
     * Pesan galatnya menyebut nomor baris supaya pengguna tahu baris mana di
     * formnya yang bermasalah.
     */
    private function validateRows(Request $request): array
    {
        $request->validate([
            'transactions' => ['nullable', 'array'],
            'transactions.*.member_id' => ['required', 'integer', 'exists:members,id'],
            'transactions.*.rate_id' => ['required', 'integer', 'exists:rates,id'],
            'transactions.*.payment_period' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{4}$/'],
            'transactions.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'transactions.*.discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ], [
            'transactions.*.member_id.required' => 'Anggota pada baris :position wajib dipilih.',
            'transactions.*.member_id.exists' => 'Anggota pada baris :position tidak ada di master.',
            'transactions.*.rate_id.required' => 'Tarif pada baris :position wajib dipilih.',
            'transactions.*.rate_id.exists' => 'Tarif pada baris :position tidak ada di master.',
            'transactions.*.payment_period.required' => 'Periode pada baris :position wajib diisi.',
            'transactions.*.payment_period.regex' => 'Periode pada baris :position harus berformat MM/YYYY.',
            'transactions.*.amount.required' => 'Nominal pada baris :position wajib diisi.',
        ]);

        $hasil = [];
        $terpakai = [];

        foreach ($request->input('transactions', []) as $i => $baris) {
            $nomor = $i + 1;
            $field = "transactions.{$i}";

            $data = [
                'member_id' => (int) $baris['member_id'],
                'rate_id' => (int) $baris['rate_id'],
                'payment_period' => $this->awalBulan((string) $baris['payment_period'], $field),
                'amount' => $baris['amount'],
                'discount' => $baris['discount'] ?? 0,
            ];

            $this->periksaDiskon($data, $field);

            // Bentrok di dalam kiriman yang sama tidak akan tertangkap
            // pengecekan database — barisnya belum tersimpan saat baris
            // berikutnya diperiksa.
            $kunci = $data['member_id'].'-'.$data['rate_id'].'-'.$data['payment_period'];

            if (isset($terpakai[$kunci])) {
                throw ValidationException::withMessages([
                    $field => "Baris {$nomor} mengulang anggota, tarif, dan periode yang sama dengan baris {$terpakai[$kunci]}.",
                ]);
            }

            $terpakai[$kunci] = $nomor;

            $this->periksaDuplikatPeriode($data, $field);

            $hasil[] = $data;
        }

        return $hasil;
    }

    public function show(TransactionHeader $transaksiHeader)
    {
        return response()->json(
            $this->transform($transaksiHeader->loadCount('transactions'), true)
        );
    }

    public function update(Request $request, TransactionHeader $transaksiHeader)
    {
        $data = $this->validateData($request, $transaksiHeader);

        // Nomor yang sudah terbit tidak boleh terhapus jadi kosong saat diedit.
        if (empty($data['transaction_number'])) {
            unset($data['transaction_number']);
        }

        $this->periksaPotongan($data);

        $transaksiHeader->update($data);

        return response()->json(
            $this->transform($transaksiHeader->fresh()->loadCount('transactions'))
        );
    }

    public function destroy(TransactionHeader $transaksiHeader)
    {
        // Soft delete. Rincian tidak ikut terhapus dan `transaction_header_id`
        // sengaja dibiarkan terisi: relasinya otomatis terbaca null karena
        // global scope menyaring header yang sudah dihapus, dan kaitannya pulih
        // utuh bila header ini di-restore. Mengosongkan kolomnya justru membuat
        // restore tidak ada gunanya.
        $transaksiHeader->delete();

        return response()->json(['message' => 'Header transaksi dihapus.']);
    }

    /**
     * `$header` diisi saat update supaya nomornya sendiri tidak terhitung
     * sebagai duplikat.
     */
    private function validateData(Request $request, ?TransactionHeader $header = null): array
    {
        $data = $request->validate([
            'transaction_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('transaction_headers', 'transaction_number')->ignore($header?->id),
            ],
            'transaction_type' => ['required', Rule::in(TransactionHeader::TRANSACTION_TYPES)],
            'total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'member_deduction' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'group_leader_deduction' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'group_leader_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'payment' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'payment_method' => ['required', Rule::in(TransactionHeader::PAYMENT_METHODS)],
        ], [
            'transaction_number.unique' => 'No. Transaksi ":input" sudah dipakai.',
            'transaction_type.required' => 'Jenis transaksi wajib dipilih.',
            'transaction_type.in' => 'Jenis transaksi hanya boleh kelompok atau pribadi.',
            'total.required' => 'Total wajib diisi.',
            'total.numeric' => 'Total harus berupa angka.',
            'payment.required' => 'Pembayaran wajib diisi.',
            'payment.numeric' => 'Pembayaran harus berupa angka.',
            'payment_method.required' => 'Cara bayar wajib dipilih.',
            'payment_method.in' => 'Cara bayar hanya boleh transfer atau cash.',
        ]);

        foreach (['member_deduction', 'group_leader_deduction', 'group_leader_fee'] as $kolom) {
            $data[$kolom] = $data[$kolom] ?? 0;
        }

        // Potongan & jasa ketua kelompok hanya berlaku pada setoran kelompok.
        // Dinolkan di sini, bukan sekadar disembunyikan di form: form yang
        // menyembunyikan field tidak menghalangi permintaan yang disusun
        // sendiri, dan angka nyasar itu tetap akan menggeser `balance`.
        if ($data['transaction_type'] === 'pribadi') {
            $data['group_leader_deduction'] = 0;
            $data['group_leader_fee'] = 0;
        }

        return $data;
    }

    /**
     * Potongan gabungan tidak boleh melebihi total — hasilnya tagihan negatif,
     * yang hampir selalu salah ketik dan lebih baik ditolak daripada tersimpan
     * diam-diam.
     *
     * Dipanggil setelah `total` final diketahui: bila ada rincian, totalnya
     * dihitung dari rincian itu dan bukan dari angka kiriman klien.
     */
    private function periksaPotongan(array $data): void
    {
        $potongan = (float) $data['member_deduction'] + (float) $data['group_leader_deduction'];

        if ($potongan > (float) $data['total']) {
            throw ValidationException::withMessages([
                'member_deduction' => 'Potongan anggota + potongan ketua kelompok tidak boleh melebihi total.',
            ]);
        }
    }

    /**
     * `balance` = yang seharusnya dibayar dikurangi yang diterima.
     * Positif berarti masih kurang bayar, negatif berarti lebih bayar.
     */
    private function transform(TransactionHeader $row, bool $denganRincian = false): array
    {
        $hasil = [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'transaction_number' => $row->transaction_number,
            'transaction_type' => $row->transaction_type,
            'total' => $row->total,
            'member_deduction' => $row->member_deduction,
            'group_leader_deduction' => $row->group_leader_deduction,
            'group_leader_fee' => $row->group_leader_fee,
            'payment' => $row->payment,
            'payment_method' => $row->payment_method,
            'balance' => $row->balance,
            'transactions_count' => $row->transactions_count ?? 0,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ];

        if ($denganRincian) {
            $hasil['transactions'] = $row->transactions()
                ->with(['member:id,member_number,name', 'rate:id,code,name'])
                ->orderBy('payment_period')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'uuid' => $t->uuid,
                    'member_id' => $t->member_id,
                    'member_number' => $t->member?->member_number,
                    'member_name' => $t->member?->name,
                    'rate_id' => $t->rate_id,
                    'rate_name' => $t->rate?->name,
                    'payment_period' => optional($t->payment_period)->format('m/Y'),
                    'amount' => $t->amount,
                    'discount' => $t->discount,
                    'total' => $t->total,
                ]);
        }

        return $hasil;
    }
}
