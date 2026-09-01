<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\GroupLeader;
use App\Models\TransactionHeader;
use App\Support\Terbilang;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Daftar jasa ketua kelompok — kuitansi yang memuat komisi ketua, beserta
 * nominal dan terbilangnya.
 *
 * Berdiri sendiri dari TransaksiHeaderController: yang dijawab halaman ini
 * bukan "kuitansi apa saja yang masuk" melainkan "berapa yang harus dibayarkan
 * ke tiap ketua", jadi penyaring, kolom, dan urutannya memang beda.
 */
class RekapJasaController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->daftar($request)->paginate($request->integer('per_page', 25));

        $data->getCollection()->transform(fn ($row) => $this->transform($row));

        return response()->json($data);
    }

    /**
     * Query daftar jasa ketua beserta seluruh penyaringnya.
     *
     * Dipakai bersama oleh daftar dan cetak massal — kalau keduanya menyusun
     * penyaringnya sendiri-sendiri, berkas PDF yang dicetak petugas suatu saat
     * akan memuat baris yang berbeda dari yang barusan ia lihat di layar, dan
     * selisih semacam itu nyaris mustahil disadari.
     */
    private function daftar(Request $request)
    {
        $query = TransactionHeader::query()
            ->select('transaction_headers.*')
            ->addSelect(['group_leader_name' => $this->subqueryNamaKetua()])
            // Inti daftar ini: hanya kuitansi yang memang berkomisi. Persentase 0
            // (atau null pada baris lama) berarti tidak ada jasa ketua sama sekali,
            // dan barisnya cuma jadi derau di daftar pembayaran komisi.
            ->whereNotNull('group_leader_fee_percent')
            ->where('group_leader_fee_percent', '!=', 0);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                    // Nama ketua ada di subquery select, yang tidak bisa dipakai
                    // di WHERE — syaratnya ditulis ulang sebagai exists.
                    ->orWhereExists(function ($sub) use ($search) {
                        $sub->selectRaw('1')
                            ->from('group_leaders')
                            ->join('members', 'members.group_leader_id', '=', 'group_leaders.id')
                            ->join('transactions', 'transactions.member_id', '=', 'members.id')
                            ->whereColumn('transactions.transaction_header_id', 'transaction_headers.id')
                            ->whereNull('transactions.deleted_by')
                            ->whereNull('members.deleted_by')
                            ->whereNull('group_leaders.deleted_by')
                            ->where('group_leaders.name', 'like', "%{$search}%");
                    });
            });
        }

        // Penyaring satu ketua kelompok. Kodenya (`group_leaders.code`, alias
        // "noketua" pada kontrak lama) dipakai sebagai nilai — bukan namanya:
        // dua ketua boleh bernama sama, kodenya tidak.
        if ($ketua = $request->query('group_leader')) {
            $query->whereExists(function ($sub) use ($ketua) {
                $sub->selectRaw('1')
                    ->from('group_leaders')
                    ->join('members', 'members.group_leader_id', '=', 'group_leaders.id')
                    ->join('transactions', 'transactions.member_id', '=', 'members.id')
                    ->whereColumn('transactions.transaction_header_id', 'transaction_headers.id')
                    ->whereNull('transactions.deleted_by')
                    ->whereNull('members.deleted_by')
                    ->whereNull('group_leaders.deleted_by')
                    ->where('group_leaders.code', $ketua);
            });
        }

        // Sama seperti daftar transaksi: tanggal uang diterima, dengan created_at
        // sebagai cadangan untuk baris lama yang kolom `date`-nya belum terisi.
        if ($dari = $request->query('date_from')) {
            $query->whereRaw('DATE(COALESCE(`date`, created_at)) >= ?', [$dari]);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereRaw('DATE(COALESCE(`date`, created_at)) <= ?', [$sampai]);
        }

        return $query->orderByDesc('transaction_number')->orderByDesc('id');
    }

    /**
     * Kuitansi (tanda terima) pembayaran jasa ketua kelompok, sebagai PDF.
     *
     * Dokumen yang BERBEDA dari biling: biling merinci iuran seluruh anggota
     * pada sebuah kuitansi setoran, lembar ini membuktikan ketua kelompoknya
     * sudah menerima komisi atas setoran itu.
     *
     * BERBEDA dari biling, lembar ini TIDAK menuntut kuitansinya sudah
     * divalidasi: tanda terima jasa dipakai saat komisinya diserahkan, dan itu
     * bisa terjadi sebelum setorannya sempat diperiksa. Konsekuensinya nominal
     * pada lembar yang belum divalidasi masih bisa bergeser bila kuitansinya
     * diubah — itu keputusan alur kerja, bukan kelalaian.
     */
    public function kuitansi(TransactionHeader $transaksiHeader): Response|JsonResponse
    {
        if ((float) $transaksiHeader->group_leader_fee_percent === 0.0) {
            return response()->json([
                'message' => 'Kuitansi ini tidak memuat jasa ketua kelompok.',
            ], 422);
        }

        $ketua = TransactionHeader::query()
            ->whereKey($transaksiHeader->id)
            ->select('id')
            ->addSelect(['group_leader_name' => $this->subqueryNamaKetua()])
            ->value('group_leader_name');

        return $this->render(
            [$this->dataLembar($transaksiHeader, $ketua)],
            "Kuitansi-Jasa-{$transaksiHeader->transaction_number}.pdf"
        );
    }

    /**
     * SATU berkas PDF berisi kuitansi jasa seluruh baris yang cocok penyaring,
     * satu kuitansi per halaman.
     *
     * Bukan sekumpulan berkas terpisah: yang dibawa petugas ke ketua-ketua
     * kelompok adalah setumpuk lembar untuk ditandatangani, dan satu dokumen
     * yang tinggal dicetak jauh lebih mudah diurus daripada puluhan berkas yang
     * harus dibuka satu per satu.
     *
     * Penyaringnya persis milik daftar (lihat `daftar()`), jadi isinya selalu
     * sama dengan yang sedang tampil di layar.
     */
    public function kuitansiMassal(Request $request): Response|JsonResponse
    {
        $baris = $this->daftar($request)->get();

        if ($baris->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada kuitansi berjasa ketua pada penyaring ini.',
            ], 422);
        }

        $lembar = $baris
            ->map(fn (TransactionHeader $row) => $this->dataLembar($row, $row->group_leader_name))
            ->all();

        $dari = $request->query('date_from') ?: 'awal';
        $sampai = $request->query('date_to') ?: 'akhir';

        return $this->render($lembar, "Kuitansi-Jasa_{$dari}_sd_{$sampai}.pdf");
    }

    /**
     * Bahan satu LEMBAR kuitansi. Dipisah supaya cetak satuan dan cetak massal
     * menghasilkan lembar yang benar-benar sama — termasuk pembulatan dan cara
     * menulis persentasenya.
     */
    private function dataLembar(TransactionHeader $row, ?string $ketua): array
    {
        $dasar = (float) $row->total - (float) $row->member_deduction;
        $jasa = round($dasar * (float) $row->group_leader_fee_percent / 100, 2);

        $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');

        return [
            'header' => $row,
            'ketua' => $ketua ?: '—',
            'tanggalCetak' => now()->translatedFormat('d F Y'),
            'nominal' => $rupiah($jasa),
            'terbilang' => Terbilang::rupiah($jasa),
        ];
    }

    /**
     * Render kumpulan lembar jadi satu PDF melintang, satu lembar per halaman.
     *
     * Landscape: isi tiap lembar cuma segelintir baris, dan pada A4 tegak
     * halamannya hampir seluruhnya kosong di bawah tanda tangan. Melintang
     * membuat proporsinya wajar sekaligus mendekati bentuk buku kuitansi.
     */
    private function render(array $lembar, string $namaBerkas): Response
    {
        return Pdf::loadView('pdf.nafsul_kuitansi_jasa', ['lembar' => $lembar])
            ->setPaper('a4', 'landscape')
            ->stream($namaBerkas);
    }

    private function transform(TransactionHeader $row): array
    {
        // Dasar perhitungan: total kuitansi SETELAH dikurangi potongan anggota.
        // Bukan `total` mentah (kolom `group_leader_fee` yang tersimpan memakai
        // itu, sehingga terlalu besar begitu ada potongan) dan bukan pula
        // `payment`, yang bisa lebih kecil karena pembayaran sebagian —
        // jasa ketua tidak boleh menyusut hanya karena setorannya dicicil.
        $dasar = (float) $row->total - (float) $row->member_deduction;
        $jasa = round($dasar * (float) $row->group_leader_fee_percent / 100, 2);

        return [
            'uuid' => $row->uuid,
            'transaction_number' => $row->transaction_number,
            'date' => optional($row->date)->toDateString(),
            'group_leader_name' => $row->group_leader_name ?? null,
            // Penentu boleh-tidaknya biling dicetak: endpoint biling menolak
            // kuitansi yang belum diperiksa, jadi tombolnya tidak ditawarkan.
            'validation_at' => optional($row->validation_at)->toDateTimeString(),
            // Angka pembentuknya ikut dikirim supaya nominalnya bisa ditelusuri
            // tanpa membuka kuitansinya satu per satu.
            'total' => $row->total,
            'member_deduction' => $row->member_deduction,
            'fee_base' => number_format($dasar, 2, '.', ''),
            'group_leader_fee_percent' => $row->group_leader_fee_percent,
            'leader_fee' => number_format($jasa, 2, '.', ''),
            'leader_fee_words' => Terbilang::rupiah($jasa),
        ];
    }

    /**
     * Nama ketua kelompok pemilik kuitansi, lewat anggota pada rincian pertama.
     *
     * Global scope `active` menulis `deleted_by` TANPA nama tabel sehingga ambigu
     * begitu ada join — scope-nya dilepas dan syaratnya ditulis ulang eksplisit.
     */
    private function subqueryNamaKetua()
    {
        return GroupLeader::query()
            ->select('group_leaders.name')
            ->join('members', 'members.group_leader_id', '=', 'group_leaders.id')
            ->join('transactions', 'transactions.member_id', '=', 'members.id')
            ->whereColumn('transactions.transaction_header_id', 'transaction_headers.id')
            ->whereNull('transactions.deleted_by')
            ->whereNull('members.deleted_by')
            ->whereNull('group_leaders.deleted_by')
            ->withoutGlobalScope('active')
            ->orderBy('transactions.id')
            ->limit(1);
    }
}
