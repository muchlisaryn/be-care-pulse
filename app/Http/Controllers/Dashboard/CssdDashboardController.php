<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\InstrumentStorage;
use App\Models\OrderItem;
use App\Traits\CountsSterileItems;
use App\Traits\SummarizesOrders;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard petugas CSSD — sudut pandang PENYEDIA alat.
 *
 * Menjawab: berapa jenis & unit yang dipegang, berapa yang siap keluar dari
 * gudang steril, alat mana yang paling laris, dan ruangan mana yang paling
 * banyak meminjam.
 *
 * Angka peminjamannya diambil dari `SummarizesOrders` — trait yang sama dengan
 * Dashboard Perawat, supaya kedua layar tidak pernah menyebut angka berbeda
 * untuk pertanyaan yang sama.
 */
class CssdDashboardController extends Controller
{
    use CountsSterileItems, SummarizesOrders;

    /** Panjang daftar "alat terlaris" & "ruangan terbanyak". */
    private const LIMIT_PERINGKAT = 10;

    /**
     * GET /api/cssd/dashboard
     *
     * Query: `date_from`, `date_to` (Y-m-d) — bawaan bulan berjalan. Rentang ini
     * hanya membatasi angka yang bersifat PERIODE (grafik peminjaman, peringkat
     * alat & ruangan). Kartu antrean kerja (order masuk, sedang dipinjam) selalu
     * berisi keadaan saat ini; lihat alasannya di `SummarizesOrders`.
     */
    public function index(Request $request): JsonResponse
    {
        [$dari, $sampai] = $this->rentangTanggal($request);

        return $this->success('Dashboard CSSD berhasil diambil.', [
            'date_from' => $dari->format('Y-m-d'),
            'date_to' => $sampai->format('Y-m-d'),
            'summary' => [
                'instrument_types' => (int) Instrument::query()->count(),
                'sterile_ready' => $this->siapDiGudangSteril(),
                'incoming_orders' => $this->orderMasuk(),
                'currently_borrowed' => $this->sedangDipinjam(),
                'period_orders' => (int) $this->orderRentang($dari, $sampai)->count(),
            ],
            'borrow_chart' => $this->grafikHarian($dari, $sampai),
            'top_instruments' => $this->alatTerlaris($dari, $sampai),
            'top_rooms' => $this->ruanganTerbanyak($dari, $sampai),
        ]);
    }

    /**
     * Isi gudang steril yang BENAR-BENAR siap dipesan.
     *
     * Memakai `sterilePool()` + `countAsItems()` — definisi baris dan aturan
     * hitung yang sama persis dengan halaman Gudang Steril (paket dihitung per
     * SET, satuan per unit). Menghitung ulang dengan cara sendiri akan membuat
     * dashboard dan halaman gudang menyebut angka berbeda untuk rak yang sama.
     *
     * Baris kedaluwarsa dan baris TANPA tanggal kedaluwarsa dikeluarkan: dua-duanya
     * masih menempati rak, tapi tidak boleh didistribusikan — sterilitasnya tidak
     * bisa dijamin. Kata "ready" di kartu ini harus berarti benar-benar bisa keluar.
     */
    private function siapDiGudangSteril(): int
    {
        $hariIni = CarbonImmutable::now()->startOfDay();

        $rows = InstrumentStorage::sterilePool()
            ->with('productionItem:id,source,package_name')
            ->get([
                'id', 'instrument_stock_id', 'sterilization_id', 'production_item_id',
                'expiry_date', 'rack_code',
            ]);

        $siap = $rows->filter(
            fn ($s) => $s->expiry_date && $s->expiry_date->startOfDay()->gte($hariIni)
        );

        // Peta barcode dibangun dari SELURUH baris, bukan hanya yang tersaring:
        // label kemasan sebuah set bisa terbawa baris yang tidak lolos saringan,
        // dan tanpa peta penuh anggota set yang sama bisa terhitung dua kali.
        return $this->countAsItems($siap, $this->packagingBarcodeMap($rows));
    }

    /**
     * Alat yang paling banyak dipinjam pada rentang terpilih.
     *
     * Dihitung per UNIT yang keluar (`order_item`), bukan per order: satu order
     * yang membawa delapan gunting memang berarti gunting delapan kali dipakai.
     *
     * Dikelompokkan lewat `instruments` — kolomnya wajib terisi karena
     * `order_item.instrument_stock_id` adalah foreign key yang tidak nullable.
     */
    private function alatTerlaris(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        // Global scope `active` menulis `deleted_by` TANPA nama tabel, sehingga
        // begitu tabel lain yang juga punya kolom itu ikut di-join, MySQL menolak
        // querynya karena ambigu. Jadi scope-nya dilepas dan syarat "belum
        // dihapus" ditulis ulang di sini secara eksplisit untuk tiap tabel.
        $baris = OrderItem::withoutGlobalScope('active')
            ->join('instrument_stocks', 'instrument_stocks.id', '=', 'order_item.instrument_stock_id')
            ->join('instruments', 'instruments.id', '=', 'instrument_stocks.instrument_id')
            ->whereNull('order_item.deleted_by')
            ->whereNull('instrument_stocks.deleted_by')
            ->whereNull('instruments.deleted_by')
            ->whereIn('order_item.order_id', $this->orderRentang($dari, $sampai)->select('order.id'))
            ->selectRaw('instruments.id as instrument_id, instruments.name, instruments.code')
            ->selectRaw('COUNT(*) as jumlah')
            ->groupBy('instruments.id', 'instruments.name', 'instruments.code')
            ->orderByDesc('jumlah')
            // Nama sebagai pemecah seri: tanpa itu urutan alat berjumlah sama
            // berubah-ubah tiap muat ulang dan daftarnya terlihat berkedip.
            ->orderBy('instruments.name')
            ->limit(self::LIMIT_PERINGKAT)
            ->get();

        $terbanyak = (int) ($baris->first()->jumlah ?? 0);

        return $baris->map(fn ($b) => [
            'instrument_id' => (int) $b->instrument_id,
            'code' => $b->code,
            'name' => $b->name,
            'total' => (int) $b->jumlah,
            // Porsi terhadap alat terlaris — dipakai panjang batang di layar,
            // supaya frontend tidak perlu menghitung ulang nilai maksimumnya.
            'percent' => $terbanyak > 0 ? round((int) $b->jumlah / $terbanyak * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * Ruangan peminjam terbanyak, urut dari terbanyak ke terkecil.
     *
     * Dihitung per ORDER (bukan per unit): yang ditanyakan adalah seberapa sering
     * sebuah ruangan datang meminjam, bukan seberapa banyak barang yang dibawa.
     *
     * Order tanpa ruangan tidak ikut — barisnya tidak bisa dinamai, dan
     * memunculkannya sebagai "—" di puncak daftar hanya menyesatkan.
     */
    private function ruanganTerbanyak(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        // Sama seperti alatTerlaris(): scope `active` dilepas karena `rooms` juga
        // punya kolom `deleted_by`, lalu syaratnya ditulis ulang per tabel.
        $baris = $this->orderRentang($dari, $sampai)
            ->withoutGlobalScope('active')
            ->join('rooms', 'rooms.id', '=', 'order.room_id')
            ->whereNull('order.deleted_by')
            ->whereNull('rooms.deleted_by')
            ->whereNotNull('order.room_id')
            ->selectRaw('rooms.id as room_id, rooms.name, rooms.code')
            ->selectRaw('COUNT(*) as jumlah')
            ->groupBy('rooms.id', 'rooms.name', 'rooms.code')
            ->orderByDesc('jumlah')
            ->orderBy('rooms.name')
            ->limit(self::LIMIT_PERINGKAT)
            ->get();

        $total = (int) $baris->sum('jumlah');

        return $baris->map(fn ($b) => [
            'room_id' => (int) $b->room_id,
            'code' => $b->code,
            'name' => $b->name,
            'total' => (int) $b->jumlah,
            // Di sini persentasenya terhadap TOTAL daftar (porsi tiap ruangan),
            // berbeda dari alat terlaris yang relatif terhadap juara — pertanyaannya
            // memang berbeda: "seberapa besar bagian ruangan ini".
            'percent' => $total > 0 ? round((int) $b->jumlah / $total * 100, 1) : 0.0,
        ])->all();
    }
}
