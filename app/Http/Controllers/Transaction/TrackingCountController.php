<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Angka badge tab "Distribution & Tracking" pada halaman Tracking Order
 * (`/cssd/tracking-order?tab=distribusi`).
 *
 * SENGAJA dipisah dari MonitoringController@counts: endpoint itu masih menyuplai
 * badge tab "Order Masuk" dengan aturan hitung yang berbeda, jadi perubahan aturan
 * di sini tidak boleh ikut menggeser angka di sana (dan sebaliknya).
 *
 * Yang dihitung adalah JEJAK WAKTU dari kolom audit, BUKAN kolom `status`.
 * Status ditulis ulang di banyak tempat sepanjang alur CSSD dan bisa tertinggal
 * kalau ada satu proses yang gagal memperbaruinya; `processed_at`,
 * `distributed_at`, `canceled_at`, dan `order_item.is_returned` masing-masing
 * hanya ditulis sekali tepat saat kejadiannya, jadi angkanya tetap benar meski
 * status meleset.
 */
class TrackingCountController extends Controller
{
    /**
     * GET /api/master/tracking-order/counts
     *
     * Murni `count()` di tabel `order` — tidak memuat satu pun baris beserta
     * relasinya. Kedua angka disaring rentang tanggal yang SAMA (`order_date`,
     * tanggal pinjam) supaya bisa dibandingkan langsung dengan isi daftarnya, dan
     * SALING LEPAS: order yang sudah diantar keluar dari "siap distribusi", order
     * yang seluruh unitnya sudah kembali keluar dari "dipinjam". Jadi tidak ada
     * satu order pun yang terhitung dua kali.
     */
    public function counts(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $request->input('from');
        $to = $request->input('to');

        // Query dasar dibuat ulang tiap pemakaian (bukan satu instance dipakai dua
        // kali) — builder Eloquent menumpuk kondisi, jadi tanpa ini syarat angka
        // pertama ikut terbawa ke angka kedua.
        //
        // Order yang sudah dihapus otomatis tersaring global scope `active`
        // (`deleted_by IS NULL`) dari trait HasAuditColumns. Order yang dibatalkan
        // dikeluarkan lewat jejaknya sendiri (`canceled_at`), bukan lewat status.
        $inRange = fn () => Order::query()
            ->whereNull('canceled_at')
            ->when($from, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('order_date', '<=', $v));

        // SIAP DISTRIBUSI — sudah diproses CSSD, belum diantar ke unit pelayanan.
        $siapDistribusi = $inRange()
            ->whereNotNull('processed_at')
            ->whereNull('distributed_at')
            ->count();

        // SUDAH DIDISTRIBUSIKAN & BELUM KEMBALI — masih ada minimal satu unit yang
        // belum ditandai kembali. Sengaja dibaca per unit (`order_item.is_returned`),
        // bukan dari `return_actual_date` di header: pengembalian boleh dicicil, jadi
        // tanggal di header sudah terisi meski sebagian unit masih di ruangan.
        $dipinjam = $inRange()
            ->whereNotNull('distributed_at')
            ->whereHas('items', fn ($q) => $q->where('is_returned', false))
            ->count();

        return $this->success('Jumlah order tab Distribution & Tracking berhasil diambil.', [
            'siap_distribusi' => $siapDistribusi,
            'dipinjam' => $dipinjam,
            // Angka yang dipajang badge — dijumlahkan di sini supaya aturannya cuma
            // ada di satu tempat.
            'total' => $siapDistribusi + $dipinjam,
        ]);
    }
}
