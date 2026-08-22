<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use App\Models\Transaction;
use App\Traits\HandlesTransactionRows;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Transaksi iuran anggota Nafsul.
 *
 * Satu baris = satu tagihan iuran seorang anggota untuk satu periode dan satu
 * tarif.
 *
 * Nama kolom database dan nama field API sama-sama Inggris. Master Nafsul lain
 * memakai `HasLegacyAttributes` untuk mempertahankan kontrak lama berbahasa
 * Indonesia; tabel ini baru dan tidak punya konsumen lama, jadi tidak perlu
 * lapisan penerjemah.
 *
 * Seperti controller Nafsul lainnya, respons dikirim sebagai JSON polos (bukan
 * pembungkus `success`/`error`) karena helper frontend `lib/nafsul/api.ts`
 * membaca body-nya langsung.
 */
class TransaksiController extends Controller
{
    use HandlesTransactionRows;

    private const RELATIONS = ['member:id,member_number,name', 'rate:id,code,name,price', 'header:id,transaction_number'];

    public function index(Request $request)
    {
        $query = Transaction::query()->with(self::RELATIONS);

        if ($search = $request->query('search')) {
            $query->whereHas('member', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('member_number', 'like', "%{$search}%");
            });
        }

        if ($memberId = $request->integer('member_id')) {
            $query->where('member_id', $memberId);
        }

        if ($rateId = $request->integer('rate_id')) {
            $query->where('rate_id', $rateId);
        }

        if ($headerId = $request->integer('transaction_header_id')) {
            $query->where('transaction_header_id', $headerId);
        }

        // Filter periode berupa rentang; masing-masing sisi berdiri sendiri.
        if ($dari = $request->query('period_from')) {
            $this->filterPeriode($query, $this->pecahPeriode($dari, 'period_from'), '>=');
        }

        if ($sampai = $request->query('period_to')) {
            $this->filterPeriode($query, $this->pecahPeriode($sampai, 'period_to'), '<=');
        }

        // Periode bisa sama antar baris; `id` dipakai sebagai pemecah seri agar
        // urutannya tidak berubah-ubah antar halaman.
        $query->urutPeriodeTerbaru();

        $data = $query->paginate($request->integer('per_page', 25));

        $data->getCollection()->transform(fn ($row) => $this->transform($row));

        return response()->json($data);
    }

    /**
     * Batas rentang periode sebagai perbandingan bertingkat pada (`year`, `month`).
     *
     * Ditulis begini, bukan sebagai `year * 100 + month` yang lebih pendek:
     * ekspresi aritmetika membuat MySQL tidak bisa memakai index
     * `transactions_periode_index` dan seluruh tabel harus dipindai. Bentuk
     * "tahunnya lebih besar, ATAU tahun sama tapi bulannya memenuhi" tetap
     * berupa perbandingan kolom biasa sehingga index-nya terpakai.
     *
     * Baris tanpa periode (tarif sekali bayar) otomatis tersaring keluar —
     * perbandingan apa pun terhadap NULL bernilai NULL, bukan true. Itu memang
     * yang diinginkan: baris yang tidak punya periode tidak berada di dalam
     * rentang periode mana pun.
     *
     * @param  array{month:int,year:int}  $batas
     * @param  '>='|'<='  $arah
     */
    private function filterPeriode(Builder $query, array $batas, string $arah): void
    {
        $tahunLebih = $arah === '>=' ? '>' : '<';

        $query->where(function (Builder $q) use ($batas, $arah, $tahunLebih) {
            $q->where('year', $tahunLebih, $batas['year'])
                ->orWhere(function (Builder $q) use ($batas, $arah) {
                    $q->where('year', $batas['year'])
                        ->where('month', $arah, $batas['month']);
                });
        });
    }

    /**
     * Susun rencana iuran beberapa bulan sekaligus.
     *
     * Petugas cukup memasukkan jumlah bulan; periode, nominal, dan diskonnya
     * dihitung di sini. Aturannya sengaja ditaruh di server, bukan di browser:
     * kalau dihitung dua kali di tempat berbeda, cepat atau lambat angka di
     * layar berbeda dari yang tersimpan.
     *
     * - Periode mulai dari bulan setelah pembayaran terakhir anggota itu pada
     *   tarif yang sama. Tiap tarif punya jadwalnya sendiri.
     * - Anggota yang belum pernah membayar dimulai dari bulan berjalan.
     * - Tiap kelipatan 12 bulan memberi 1 bulan gratis, dikenakan pada
     *   bulan-bulan terakhir dalam rencana (diskon = nominal penuh).
     *
     * Tidak menyimpan apa pun — hasilnya dipakai frontend untuk mengisi form.
     */
    public function rencana(Request $request)
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
            // Wajib untuk tarif berulang saja — tarif sekali bayar tidak punya
            // periode, jadi tidak ada yang bisa dikalikan. Dibatasi 120 bulan
            // (10 tahun): di atas itu hampir pasti salah ketik, dan barisnya
            // jadi terlalu banyak untuk ditinjau petugas.
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ], [
            'member_id.required' => 'Anggota wajib dipilih.',
            'member_id.exists' => 'Anggota tidak ada di master.',
            'rate_id.required' => 'Tarif wajib dipilih.',
            'rate_id.exists' => 'Tarif tidak ada di master.',
            'months.integer' => 'Jumlah bulan harus berupa angka bulat.',
            'months.min' => 'Jumlah bulan minimal 1.',
            'months.max' => 'Jumlah bulan maksimal 120 (10 tahun).',
        ]);

        $tarif = Rate::whereKey($data['rate_id'])->first(['id', 'price', 'fee_type']);
        $nominal = (float) $tarif->price;

        // Tarif sekali bayar: satu baris tanpa periode, tanpa bulan gratis.
        // Rencana tetap dikembalikan dalam bentuk yang sama supaya frontend
        // tidak perlu dua cabang untuk membaca hasilnya.
        if ($tarif->fee_type === Rate::FEE_TYPE_ONE_TIME) {
            return response()->json($this->rencanaSekaliBayar($data, $nominal));
        }

        if ($data['months'] === null) {
            throw ValidationException::withMessages([
                'months' => 'Jumlah bulan wajib diisi untuk tarif berulang.',
            ]);
        }

        $bulan = (int) $data['months'];
        $mulai = $this->periodeBerikutnya((int) $data['member_id'], (int) $data['rate_id']);

        // Tiap genap 12 bulan dapat 1 bulan gratis; 11 bulan tidak dapat apa-apa,
        // 24 bulan dapat 2, dan seterusnya.
        $gratis = intdiv($bulan, 12);
        $indeksGratisMulai = $bulan - $gratis;

        $baris = [];

        for ($i = 0; $i < $bulan; $i++) {
            $periode = $mulai->copy()->addMonths($i);
            $isGratis = $i >= $indeksGratisMulai;
            $diskon = $isGratis ? $nominal : 0.0;

            $baris[] = [
                'payment_period' => $periode->format('m/Y'),
                'amount' => number_format($nominal, 2, '.', ''),
                'discount' => number_format($diskon, 2, '.', ''),
                'total' => number_format($nominal - $diskon, 2, '.', ''),
                'free' => $isGratis,
            ];
        }

        return response()->json([
            'member_id' => (int) $data['member_id'],
            'rate_id' => (int) $data['rate_id'],
            'months' => $bulan,
            'free_months' => $gratis,
            'start_period' => $mulai->format('m/Y'),
            'end_period' => $mulai->copy()->addMonths($bulan - 1)->format('m/Y'),
            'total' => number_format(array_sum(array_map(
                fn ($b) => (float) $b['total'],
                $baris
            )), 2, '.', ''),
            'transactions' => $baris,
        ]);
    }

    /**
     * Bulan pertama yang belum terbayar untuk satu anggota pada satu tarif.
     *
     * `withTrashed()` dipakai supaya periode milik baris yang sudah dihapus
     * tidak diusulkan lagi — index unik di database mencakup baris terhapus,
     * jadi periode itu tetap akan ditolak saat disimpan.
     */
    private function periodeBerikutnya(int $memberId, int $rateId): Carbon
    {
        $terakhir = Transaction::withTrashed()
            ->where('member_id', $memberId)
            ->where('rate_id', $rateId)
            // Baris tarif sekali bayar tidak punya periode dan tidak boleh ikut
            // menggeser jadwal iuran berikutnya.
            ->whereNotNull('year')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first(['year', 'month']);

        return $terakhir
            ? Carbon::createFromDate($terakhir->year, $terakhir->month, 1)->addMonth()
            : now()->startOfMonth();
    }

    /**
     * Rencana untuk tarif sekali bayar: satu baris, tanpa periode.
     *
     * `payment_period` sengaja dikirim `null`, bukan dihilangkan dari struktur —
     * frontend membaca field yang sama untuk kedua jenis tarif dan hanya
     * menyembunyikannya saat kosong.
     *
     * @param  array{member_id:int|string,rate_id:int|string}  $data
     */
    private function rencanaSekaliBayar(array $data, float $nominal): array
    {
        $nominalTeks = number_format($nominal, 2, '.', '');

        return [
            'member_id' => (int) $data['member_id'],
            'rate_id' => (int) $data['rate_id'],
            'months' => 0,
            'free_months' => 0,
            'start_period' => null,
            'end_period' => null,
            'total' => $nominalTeks,
            'transactions' => [[
                'payment_period' => null,
                'amount' => $nominalTeks,
                'discount' => '0.00',
                'total' => $nominalTeks,
                'free' => false,
            ]],
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $payment = Transaction::create($data);

        return response()->json($this->transform($payment->load(self::RELATIONS)), 201);
    }

    public function show(Transaction $transaksi)
    {
        return response()->json($this->transform($transaksi->load(self::RELATIONS)));
    }

    public function update(Request $request, Transaction $transaksi)
    {
        $data = $this->validateData($request, $transaksi);

        $transaksi->update($data);

        return response()->json($this->transform($transaksi->fresh(self::RELATIONS)));
    }

    public function destroy(Transaction $transaksi)
    {
        $transaksi->delete();

        return response()->json(['message' => 'Transaksi iuran dihapus.']);
    }

    /**
     * `$payment` diisi saat update supaya barisnya sendiri tidak terhitung
     * sebagai duplikat periode.
     */
    private function validateData(Request $request, ?Transaction $payment = null): array
    {
        $data = $request->validate([
            // Boleh kosong: rincian bisa dicatat lebih dulu sebagai tagihan
            // yang belum dibayar, lalu menyusul dikaitkan ke header saat
            // pembayarannya terjadi.
            'transaction_header_id' => ['nullable', 'integer', 'exists:transaction_headers,id'],
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
            // Periode diperiksa dengan regex, bukan aturan `date`: "08/2026"
            // bukan tanggal yang sah bagi Laravel, padahal itulah bentuk yang
            // dipakai di UI.
            //
            // `nullable` di sini bukan berarti opsional: wajib-tidaknya
            // ditentukan `fee_type` tarif yang dipilih, dan itu diperiksa
            // periodeUntukTarif() di bawah — aturan validasi biasa tidak bisa
            // melihat isi tabel `rates`.
            'payment_period' => ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{4}$/'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ], [
            'transaction_header_id.exists' => 'Header transaksi tidak ditemukan.',
            'member_id.required' => 'Anggota wajib dipilih.',
            'member_id.exists' => 'Anggota tidak ada di master.',
            'rate_id.required' => 'Tarif wajib dipilih.',
            'rate_id.exists' => 'Tarif tidak ada di master.',
            'payment_period.regex' => 'Periode pembayaran harus berformat MM/YYYY, contoh 08/2026.',
            'amount.required' => 'Nominal wajib diisi.',
            'amount.numeric' => 'Nominal harus berupa angka.',
            'discount.numeric' => 'Diskon harus berupa angka.',
        ]);

        $data += $this->periodeUntukTarif(
            $data['payment_period'] ?? null,
            (int) $data['rate_id'],
            'payment_period'
        );

        unset($data['payment_period']);

        $data['discount'] = $data['discount'] ?? 0;

        $this->periksaDiskon($data, 'discount');
        $this->periksaDuplikatPeriode($data, 'payment_period', $payment?->id);

        return $data;
    }

    /** Periode dikirim balik sebagai "MM/YYYY" — bentuk yang sama dengan yang diterima. */
    private function transform(Transaction $row): array
    {
        return [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'transaction_header_id' => $row->transaction_header_id,
            'transaction_number' => $row->header?->transaction_number,
            'member_id' => $row->member_id,
            'member_number' => $row->member?->member_number,
            'member_name' => $row->member?->name,
            'rate_id' => $row->rate_id,
            'rate_code' => $row->rate?->code,
            'rate_name' => $row->rate?->name,
            // Dirakit accessor Transaction dari kolom `month` + `year`;
            // `null` untuk tarif sekali bayar.
            'payment_period' => $row->payment_period,
            'amount' => $row->amount,
            'discount' => $row->discount,
            'total' => $row->total,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ];
    }
}
