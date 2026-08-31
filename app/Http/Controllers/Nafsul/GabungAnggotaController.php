<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberMerge;
use App\Models\MemberMergeItem;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gabung Anggota — memindahkan transaksi iuran dari satu anggota ke anggota lain.
 *
 * Dipakai untuk data ganda: satu orang yang tanpa sengaja terdaftar dua kali
 * (mis. beda ejaan nama, atau nomor anggota lama & baru), sehingga setorannya
 * tercatat terpecah di dua kartu.
 *
 * Yang BENAR-BENAR berpindah cuma satu kolom: `transactions.member_id`. Kuitansi
 * (`transaction_headers`) TIDAK ikut dipindahkan, dan itu disengaja — satu
 * kuitansi bisa memuat rincian milik beberapa anggota sekaligus (setoran satu
 * kelompok), jadi memindahkan kuitansinya akan ikut menyeret rincian milik orang
 * lain. Petugas memilih per nomor kuitansi hanya sebagai cara mengelompokkan;
 * yang berpindah tetap rincian milik anggota asal saja.
 *
 * Anggota asal TIDAK dihapus. Begitu ia tidak menyisakan transaksi apa pun, ia
 * ditandai nonaktif (`merged_at` terisi → `disabled` = true) sehingga hilang dari
 * seluruh layar aplikasi, tapi barisnya tetap ada agar riwayat penggabungan bisa
 * dibaca dan — bila perlu — ditelusuri balik lewat `member_merge_items`.
 */
class GabungAnggotaController extends Controller
{
    /**
     * GET /api/nafsul/gabung-anggota/anggota/{member}/transaksi
     *
     * Seluruh transaksi seorang anggota, DIKELOMPOKKAN per nomor kuitansi —
     * bahan langkah 3 & 4 di layar penggabungan.
     *
     * Rincian yang belum punya kuitansi (`transaction_header_id` NULL) ikut
     * ditampilkan sebagai satu kelompok tersendiri. Itu tagihan yang sudah
     * dicatat tapi belum dibayar, dan ia HARUS bisa ikut dipindahkan: kalau
     * tidak, anggota asal selalu menyisakan baris dan tidak pernah bisa menjadi
     * nonaktif.
     */
    public function transaksi(Request $request, Member $member): JsonResponse
    {
        $request->validate(['target_member_id' => 'nullable|integer']);

        $baris = Transaction::where('member_id', $member->id)
            ->with([
                'rate:id,code,name',
                'header:id,transaction_number,date,payment_method,validation_at',
            ])
            ->urutPeriodeTerbaru()
            ->get();

        // Bentrok periode ditandai SEJAK DAFTARNYA MUNCUL, bukan baru ketahuan
        // saat tombol ditekan: petugas harus bisa melihat baris mana yang tidak
        // bisa dipindahkan sebelum ia memilih, bukan menerima penolakan setelah
        // mencentang tiga puluh kuitansi.
        $bentrok = $this->kunciBentrok($request->integer('target_member_id') ?: null);

        // Dikelompokkan di PHP, bukan lewat GROUP BY: tiap kelompok tetap harus
        // membawa rincian barisnya untuk ditampilkan saat kelompoknya dibuka,
        // dan itu tidak bisa dihasilkan satu query agregat.
        $kelompok = $baris
            ->groupBy(fn ($t) => $t->transaction_header_id ?? 'tanpa-kuitansi')
            ->map(function ($isi, $kunci) use ($bentrok) {
                $header = $isi->first()->header;
                $jumlahBentrok = $isi->filter(fn ($t) => $this->bentrok($t, $bentrok))->count();

                return [
                    'transaction_header_id' => $kunci === 'tanpa-kuitansi' ? null : (int) $kunci,
                    'transaction_number' => $header?->transaction_number,
                    'date' => $header?->date?->format('Y-m-d'),
                    'payment_method' => $header?->payment_method,
                    // Kuitansi yang sudah divalidasi ditandai agar petugas tahu
                    // ia sedang memindahkan setoran yang sudah diperiksa.
                    'is_validated' => $header?->validation_at !== null,
                    'transaction_count' => $isi->count(),
                    'amount' => round((float) $isi->sum(fn ($t) => (float) $t->total), 2),
                    // Satu baris bentrok saja sudah membuat SELURUH kelompok tak
                    // bisa dipindahkan: perpindahan dijalankan sebagai satu
                    // transaksi database, jadi tidak ada "sebagian berhasil".
                    'conflict_count' => $jumlahBentrok,
                    'can_merge' => $jumlahBentrok === 0,
                    'transactions' => $isi->map(fn ($t) => [
                        'id' => $t->id,
                        'uuid' => $t->uuid,
                        'rate' => $t->rate?->name,
                        'payment_period' => $t->payment_period,
                        'amount' => $t->amount,
                        'discount' => $t->discount,
                        'total' => $t->total,
                        'conflict' => $this->bentrok($t, $bentrok),
                    ])->values(),
                ];
            })
            ->values();

        return $this->success('Transaksi anggota berhasil diambil.', [
            'member' => $this->ringkasAnggota($member),
            'total_headers' => $kelompok->count(),
            'total_transactions' => $baris->count(),
            'total_amount' => round((float) $baris->sum(fn ($t) => (float) $t->total), 2),
            'conflict_count' => $baris->filter(fn ($t) => $this->bentrok($t, $bentrok))->count(),
            'groups' => $kelompok,
        ]);
    }

    /**
     * GET /api/nafsul/gabung-anggota
     *
     * Riwayat seluruh penggabungan, terbaru dulu.
     *
     * Query: `search` (nama/nomor kedua belah pihak), `date_from` & `date_to`
     * (Y-m-d), `per_page` (bawaan 25).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $query = MemberMerge::with(['sourceMember:id,member_number,name', 'targetMember:id,member_number,name']);

        // Disaring lewat `created_at` — KAPAN penggabungan dilakukan. Tidak ada
        // kolom tanggal lain yang masuk akal di sini: `member_merges` mencatat
        // peristiwa, bukan periode iuran yang dipindahkannya.
        if ($dari = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dari);
        }

        if ($sampai = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $sampai);
        }

        // Dicari lewat nama/nomor kedua belah pihak — itulah yang diingat orang
        // saat menelusuri "ke mana transaksi si A tadi pindah".
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                foreach (['sourceMember', 'targetMember'] as $relasi) {
                    $q->orWhereHas($relasi, fn ($m) => $m->withDisabled()
                        ->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                            ->orWhere('member_number', 'like', "%{$search}%")));
                }
            });
        }

        $data = $query->orderByDesc('id')->paginate($request->integer('per_page', 25));

        $data->through(fn (MemberMerge $m) => $this->ringkasGabung($m));

        return response()->json($data);
    }

    /**
     * GET /api/nafsul/gabung-anggota/{gabung}
     *
     * Satu penggabungan beserta seluruh rincian transaksi yang berpindah.
     */
    public function show(MemberMerge $gabung): JsonResponse
    {
        $gabung->load([
            'sourceMember:id,member_number,name',
            'targetMember:id,member_number,name',
            'items',
        ]);

        return $this->success('Rincian penggabungan berhasil diambil.', [
            ...$this->ringkasGabung($gabung),
            'items' => $gabung->items->map(fn ($i) => [
                'transaction_id' => $i->transaction_id,
                // `moved` = berpindah ke anggota tujuan; `disabled` = periodenya
                // bentrok sehingga rincian ini dinonaktifkan, bukan dipindahkan.
                'action' => $i->action,
                'transaction_header_id' => $i->transaction_header_id,
                'transaction_number' => $i->transaction_number,
                'amount' => $i->amount,
            ])->values(),
        ]);
    }

    /**
     * POST /api/nafsul/gabung-anggota
     *
     * Pindahkan transaksi anggota asal ke anggota tujuan.
     *
     * Body:
     *  - `source_member_id`        — anggota yang transaksinya diambil;
     *  - `target_member_id`        — anggota tujuan;
     *  - `all`                     — `true` = seluruh transaksi, tanpa perlu memilih;
     *  - `transaction_header_ids`  — nomor kuitansi terpilih (wajib bila `all` false);
     *  - `include_without_header`  — ikutkan rincian yang belum berkuitansi;
     *  - `note`                    — catatan bebas, opsional.
     *
     * SELURUHNYA dalam satu transaksi database. Kalau ada satu baris yang gagal
     * dipindahkan, tidak ada satu pun yang berpindah — perpindahan separuh jalan
     * meninggalkan setoran yang tidak jelas lagi milik siapa.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_member_id' => 'required|integer|different:target_member_id',
            'target_member_id' => 'required|integer',
            'all' => 'nullable|boolean',
            'transaction_header_ids' => 'nullable|array',
            'transaction_header_ids.*' => 'integer',
            'include_without_header' => 'nullable|boolean',
            // Bentrok periode: `false` (bawaan) menolak penggabungan seperti
            // sebelumnya; `true` menonaktifkan rincian anggota ASAL yang
            // bentrok alih-alih memindahkannya. Sengaja HARUS diminta
            // eksplisit — ia menghapus (lunak) catatan setoran, dan itu tidak
            // boleh terjadi sebagai efek samping yang tak diminta.
            'resolve_conflicts' => 'nullable|boolean',
            'note' => 'nullable|string|max:255',
        ], [
            'source_member_id.different' => 'Anggota asal dan anggota tujuan tidak boleh sama.',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                // `lockForUpdate()`: dua petugas yang menggabungkan anggota yang
                // sama pada saat bersamaan bisa sama-sama membaca "masih ada
                // sisa transaksi" lalu sama-sama memutuskan TIDAK menonaktifkan
                // anggota asal — padahal setelah keduanya selesai, sisanya nol.
                $asal = Member::whereKey($data['source_member_id'])->lockForUpdate()->first();
                $tujuan = Member::whereKey($data['target_member_id'])->lockForUpdate()->first();

                if (! $asal || ! $tujuan) {
                    // Anggota nonaktif sengaja dilaporkan "tidak ditemukan" —
                    // ia memang tidak boleh jadi asal maupun tujuan: yang sudah
                    // digabungkan tidak menyisakan transaksi, dan menjadikannya
                    // tujuan berarti menaruh setoran di kartu yang tak terlihat.
                    throw ValidationException::withMessages([
                        'source_member_id' => 'Anggota asal atau anggota tujuan tidak ditemukan / sudah nonaktif.',
                    ]);
                }

                $dipindah = $this->pilihTransaksi($asal, $data);

                /**
                 * Tidak ada yang dipindahkan — dua keadaan yang berbeda jauh,
                 * dan hanya satu yang boleh lewat.
                 *
                 *  - Anggota asal memang BELUM PUNYA transaksi sama sekali.
                 *    Penggabungannya tetap sah dan justru inti persoalannya:
                 *    anggota ganda yang belum sempat menyetor apa pun tetap
                 *    harus bisa dilebur ke kartu yang benar, dan yang kosong
                 *    dinonaktifkan. Menolaknya berarti data ganda paling
                 *    sepele malah satu-satunya yang tidak bisa dibereskan.
                 *
                 *  - Anggota asal PUNYA transaksi, tapi tidak satu pun yang
                 *    terpilih. Itu salah pakai, dan tetap ditolak: yang terjadi
                 *    cuma satu baris riwayat penggabungan kosong yang terbaca
                 *    seperti pekerjaan sudah beres, padahal transaksinya masih
                 *    tertinggal di kartu lama.
                 */
                if ($dipindah->isEmpty() && Transaction::where('member_id', $asal->id)->exists()) {
                    throw ValidationException::withMessages([
                        'transaction_header_ids' => 'Tidak ada transaksi yang cocok untuk dipindahkan.',
                    ]);
                }

                // Bentrok periode diperiksa DI SINI, sebelum satu baris pun
                // disentuh. Tanpa ini, index unik `transactions_unik` yang
                // menolaknya di tengah jalan — dan yang sampai ke petugas cuma
                // galat SQL mentah "Duplicate entry '17-1-1-2026'", yang tidak
                // menyebut anggota, tarif, maupun periode mana yang bermasalah.
                //
                // Pemeriksaan di dalam transaksi & setelah `lockForUpdate()`,
                // bukan memakai hasil yang tadi ditandai di layar: daftar itu
                // bisa sudah basi ketika tombolnya ditekan.
                $kunciBentrok = $this->kunciBentrok($tujuan->id);
                $bentrok = $dipindah->filter(fn ($t) => $this->bentrok($t, $kunciBentrok));
                $bisaPindah = $dipindah->reject(fn ($t) => $this->bentrok($t, $kunciBentrok));

                // Tanpa izin eksplisit, bentrok tetap menolak seluruh
                // penggabungan — perilaku lama, dan tetap yang paling aman.
                if ($bentrok->isNotEmpty() && empty($data['resolve_conflicts'])) {
                    $this->tolakKarenaBentrok($bentrok, $tujuan);
                }

                $gabung = MemberMerge::create([
                    'source_member_id' => $asal->id,
                    'target_member_id' => $tujuan->id,
                    'header_count' => $dipindah->pluck('transaction_header_id')->unique()->count(),
                    // Hanya yang benar-benar berpindah yang dihitung sebagai
                    // `transaction_count`; yang dinonaktifkan punya angkanya
                    // sendiri. Kalau digabung, riwayat akan menyebut nominal
                    // yang berpindah lebih besar dari yang sebenarnya.
                    'transaction_count' => $bisaPindah->count(),
                    'disabled_count' => $bentrok->count(),
                    'amount' => round((float) $bisaPindah->sum(fn ($t) => (float) $t->total), 2),
                    'note' => $data['note'] ?? null,
                ]);

                foreach ($dipindah as $transaksi) {
                    $dinonaktifkan = $this->bentrok($transaksi, $kunciBentrok);

                    // Rinciannya dicatat SEBELUM barisnya diubah: setelah
                    // `member_id` ditimpa atau barisnya dihapus, pemilik lamanya
                    // tidak bisa dibaca lagi dari mana pun.
                    $gabung->items()->create([
                        'transaction_id' => $transaksi->id,
                        'action' => $dinonaktifkan
                            ? MemberMergeItem::ACTION_DISABLED
                            : MemberMergeItem::ACTION_MOVED,
                        'transaction_header_id' => $transaksi->transaction_header_id,
                        'transaction_number' => $transaksi->header?->transaction_number,
                        'previous_member_id' => $asal->id,
                        'amount' => $transaksi->total,
                    ]);

                    if ($dinonaktifkan) {
                        // Soft delete lewat `HasAuditColumns::delete()`, yang
                        // mengisi `deleted_at` / `deleted_by` / `deleted_user_id`
                        // sekaligus menurunkan `disabled` = true. Barisnya TIDAK
                        // dihapus keras: ini catatan setoran, dan jejaknya harus
                        // tetap bisa ditelusuri lewat riwayat penggabungan.
                        //
                        // Yang dinonaktifkan milik anggota ASAL; baris milik
                        // tujuan dibiarkan utuh karena itulah yang akan terus
                        // dipakai.
                        $transaksi->delete();

                        continue;
                    }

                    // Disimpan lewat INSTANSI model, bukan `whereIn()->update()`:
                    // hanya jalur ini yang memicu event `updating` sehingga
                    // `updated_by` & `updated_at` tiap baris ikut tercatat.
                    $transaksi->member_id = $tujuan->id;
                    $transaksi->save();
                }

                // Sisa transaksi dihitung ULANG dari database, bukan disimpulkan
                // dari "yang dipilih = yang ada": di antara pemuatan daftar di
                // layar dan penekanan tombol, bisa saja ada rincian baru yang
                // masuk atas nama anggota asal.
                $sisa = Transaction::where('member_id', $asal->id)->count();

                if ($sisa === 0) {
                    $asal->merged_at = now();
                    $asal->merged_to_member_id = $tujuan->id;
                    // `disabled` tidak diisi di sini — model Member yang
                    // menurunkannya lewat event `saving`.
                    $asal->save();

                    $gabung->source_disabled = true;
                    $gabung->save();
                }

                $gabung->load(['sourceMember:id,member_number,name', 'targetMember:id,member_number,name']);

                // Rincian yang dinonaktifkan SELALU disebut di pesannya. Ia
                // menghapus catatan setoran, jadi tidak boleh berlalu diam-diam
                // hanya karena penggabungannya sendiri berhasil.
                // Anggota tanpa transaksi tidak boleh dilaporkan "seluruh
                // transaksi berhasil dipindahkan" — kalimat itu menyiratkan ada
                // yang berpindah, dan petugas akan mencari-cari di kartu tujuan.
                if ($dipindah->isEmpty()) {
                    $pesan = 'Anggota asal tidak punya transaksi, jadi tidak ada yang dipindahkan. Anggota asal dinonaktifkan.';
                } elseif ($sisa === 0) {
                    $pesan = 'Seluruh transaksi berhasil dipindahkan. Anggota asal dinonaktifkan.';
                } else {
                    $pesan = 'Transaksi terpilih berhasil dipindahkan.';
                }

                if ($bentrok->isNotEmpty()) {
                    $pesan .= ' '.$bentrok->count().' rincian yang periodenya bentrok dinonaktifkan, '
                        .'bukan dipindahkan.';
                }

                return $this->success(
                    $pesan,
                    [...$this->ringkasGabung($gabung), 'remaining_transactions' => $sisa],
                    201
                );
            });
        } catch (ValidationException $e) {
            // Diteruskan apa adanya supaya ditangani handler global sebagai 422
            // lengkap dengan `errors`, bukan tertelan catch di bawahnya jadi 500.
            throw $e;
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Rincian transaksi anggota asal yang ikut dipindahkan.
     *
     * Selalu dibatasi `member_id` anggota asal — itulah yang menjamin rincian
     * milik anggota LAIN pada kuitansi yang sama tidak ikut terbawa.
     *
     * @return \Illuminate\Support\Collection<int,Transaction>
     */
    private function pilihTransaksi(Member $asal, array $data)
    {
        // `rate` ikut dimuat untuk menamai tarif pada pesan bentrok periode.
        $query = Transaction::where('member_id', $asal->id)
            ->with(['header:id,transaction_number', 'rate:id,name']);

        if (! empty($data['all'])) {
            return $query->get();
        }

        $headerIds = $data['transaction_header_ids'] ?? [];
        $tanpaKuitansi = ! empty($data['include_without_header']);

        if ($headerIds === [] && ! $tanpaKuitansi) {
            throw ValidationException::withMessages([
                'transaction_header_ids' => 'Pilih minimal satu nomor transaksi, atau centang "pilih semua".',
            ]);
        }

        return $query->where(function ($q) use ($headerIds, $tanpaKuitansi) {
            if ($headerIds !== []) {
                $q->whereIn('transaction_header_id', $headerIds);
            }

            if ($tanpaKuitansi) {
                $q->orWhereNull('transaction_header_id');
            }
        })->get();
    }

    /**
     * Tolak penggabungan yang akan menabrak transaksi milik anggota tujuan.
     *
     * Dipakai HANYA saat `resolve_conflicts` tidak diminta. Pesannya menyebut
     * tarif & periode yang bentrok satu per satu (maksimal lima, sisanya
     * diringkas) — itulah yang dibutuhkan petugas untuk memutuskan: membereskan
     * datanya sendiri, atau menyuruh sistem menonaktifkan yang bentrok.
     *
     * @param  \Illuminate\Support\Collection<int,Transaction>  $bentrok
     */
    private function tolakKarenaBentrok($bentrok, Member $tujuan): void
    {
        $contoh = $bentrok->take(5)
            ->map(fn ($t) => trim(($t->rate?->name ?? 'Tarif').' '.$t->payment_period))
            ->implode(', ');

        $sisa = $bentrok->count() - min(5, $bentrok->count());

        throw ValidationException::withMessages([
            'transaction_header_ids' => "{$tujuan->name} sudah punya transaksi untuk tarif dan periode yang sama: "
                .$contoh
                .($sisa > 0 ? ", dan {$sisa} lainnya" : '')
                .'. Perbaiki salah satunya dulu, atau ulangi dengan pilihan '
                .'"nonaktifkan rincian yang bentrok".',
        ]);
    }

    /**
     * Kunci `tarif-bulan-tahun` yang SUDAH dimiliki anggota tujuan.
     *
     * Bentuknya cerminan index unik `transactions_unik`
     * (`member_id, rate_id, month, year`), dikurangi `member_id` yang di sini
     * selalu si anggota tujuan.
     *
     * Baris berperiode NULL (tarif sekali bayar) SENGAJA tidak dimuat: MySQL
     * memperlakukan NULL sebagai nilai yang selalu berbeda pada index unik, jadi
     * baris seperti itu tidak pernah bisa bentrok — memasukkannya ke sini justru
     * akan menolak perpindahan yang sebenarnya sah.
     *
     * @return array<string,true>
     */
    private function kunciBentrok(?int $targetMemberId): array
    {
        if (! $targetMemberId) {
            return [];
        }

        // `withTrashed()` WAJIB, dan ini bukan kehati-hatian berlebihan:
        // `transactions_unik` adalah index unik biasa, sehingga baris yang sudah
        // dihapus LUNAK tetap menempati kuncinya. Tanpa ini, transaksi tujuan
        // yang pernah dihapus tidak terlihat di pemeriksaan ini tapi tetap
        // menolak perpindahannya di database. Aturan yang sama dipakai
        // `HandlesTransactionRows::periksaDuplikatPeriode()`.
        return Transaction::withTrashed()
            ->where('member_id', $targetMemberId)
            ->whereNotNull('month')
            ->whereNotNull('year')
            ->get(['rate_id', 'month', 'year'])
            ->mapWithKeys(fn ($t) => [$t->rate_id.'-'.$t->month.'-'.$t->year => true])
            ->all();
    }

    /**
     * Apakah rincian ini akan menabrak transaksi yang sudah dimiliki tujuan.
     *
     * @param  array<string,true>  $kunci
     */
    private function bentrok(Transaction $t, array $kunci): bool
    {
        if ($t->month === null || $t->year === null) {
            return false;
        }

        return isset($kunci[$t->rate_id.'-'.$t->month.'-'.$t->year]);
    }

    /** Bentuk anggota yang dipakai di seluruh respons controller ini. */
    private function ringkasAnggota(?Member $member): ?array
    {
        if (! $member) {
            return null;
        }

        return [
            'id' => $member->id,
            'no_anggota' => $member->member_number,
            'nama' => $member->name,
            'disabled' => (bool) $member->disabled,
        ];
    }

    /** Bentuk satu baris riwayat penggabungan. */
    private function ringkasGabung(MemberMerge $m): array
    {
        return [
            'id' => $m->id,
            'uuid' => $m->uuid,
            'anggota_asal' => $this->ringkasAnggota($m->sourceMember),
            'anggota_tujuan' => $this->ringkasAnggota($m->targetMember),
            'header_count' => (int) $m->header_count,
            'transaction_count' => (int) $m->transaction_count,
            // Rincian yang DINONAKTIFKAN karena periodenya bentrok — dipisahkan
            // dari `transaction_count` supaya riwayat tidak menyebut nominal
            // yang berpindah lebih besar dari yang sebenarnya.
            'disabled_count' => (int) $m->disabled_count,
            'amount' => $m->amount,
            'source_disabled' => (bool) $m->source_disabled,
            'note' => $m->note,
            'created_at' => $m->created_at?->format('Y-m-d H:i:s'),
            'created_by' => $m->created_by,
        ];
    }
}
