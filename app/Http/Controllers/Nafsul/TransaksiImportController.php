<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Rate;
use App\Models\Transaction;
use App\Models\TransactionHeader;
use App\Traits\HandlesTransactionRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Impor transaksi iuran dari file Excel.
 *
 * Satu file, **dua sheet data**, sekali unggah:
 *
 * | Sheet       | Isi                        | Dikirim sebagai |
 * | ----------- | -------------------------- | --------------- |
 * | `Kuitansi`  | satu baris per kuitansi    | `headers`       |
 * | `Rincian`   | satu baris per iuran       | `rows`          |
 *
 * Judul kolom di file memakai NAMA KOLOM DATABASE (`date`, `payment`, `amount`,
 * …), bukan label bahasa Indonesia — file ini dipakai memindahkan data dari
 * sistem lama, dan yang memetakannya membaca skema tabel, bukan layar aplikasi.
 * Kunci payload yang diterima endpoint ini TIDAK ikut berubah (`tanggal`,
 * `dibayar`, …): frontend yang menerjemahkan judul kolom ke kunci itu.
 *
 * Kolom WAJIB sheet `Kuitansi`: `transaction_number`, `date`, `transaction_type`,
 * `payment`, `payment_method`. Sisanya boleh kosong dan diperlakukan sebagai
 * nol — kecuali `group_leader_fee`, yang bila dikosongkan justru DIHITUNG dari
 * `group_leader_fee_percent` × total kuitansi (`payment` − `member_deduction`).
 *
 * Kolom WAJIB sheet `Rincian`: `transaction_number`, `member_number`, `rate_code`.
 * `amount` boleh kosong — server memakai harga tarifnya, supaya petugas tidak
 * perlu menyalin angka yang sama ratusan kali. `payment_period` mengikuti FILE,
 * bukan `fee_type` tarifnya: diisi → tersimpan, dikosongkan → kosong. Lihat
 * rincianDariBaris() untuk alasan klasifikasi master tidak dipakai di sini.
 *
 * Keduanya dihubungkan kolom `transaction_number`, yang WAJIB diisi dan wajib
 * unik. Nomor itu sekaligus yang tersimpan sebagai nomor kuitansinya.
 *
 * Dulu ada kolom terpisah `kode_kuitansi` sebagai perekat, dan `transaction_number`
 * boleh dikosongkan agar server yang membuatkan. Dua kolom untuk satu peran
 * ternyata cuma jebakan: nomor lama diisikan ke kolom perekat yang memang selalu
 * dibuang, lalu kuitansinya tersimpan dengan nomor buatan server — tanpa galat,
 * dan tanpa jalan menelusuri balik ke arsip kertasnya.
 *
 * Karena impor selalu membawa nomornya sendiri, tidak ada lagi penomoran otomatis
 * di jalur ini. `TransactionHeader::generateNumber()` tetap dipakai jalur simpan
 * lewat form, tempat nomor memang belum ada saat kuitansinya dibuat.
 *
 * Bentuk dua sheet dipilih daripada satu sheet yang mengulang kolom kuitansi di
 * tiap baris rincian: pengulangan itu membuat satu kuitansi bisa menyebut dua
 * nilai "Dibayar" yang berbeda, dan tidak ada cara benar untuk memilih salah
 * satunya. Dengan sheet terpisah, keadaan itu tidak bisa terjadi sama sekali.
 *
 * Seorang anggota yang membayar 12 bulan sekaligus ditulis 12 baris di sheet
 * Rincian + 1 baris di sheet Kuitansi, dan menghasilkan satu kuitansi berisi 12
 * rincian — bukan 12 kuitansi terpisah.
 *
 * Potongan yang melebihi total rincian TIDAK ditolak di sini, berbeda dengan
 * jalur simpan form (TransaksiHeaderController::periksaPotongan). Data migrasi
 * membawa potongan yang tercatat di tingkat kuitansi dan tidak selalu
 * rekonsiliasi dengan rinciannya; menolaknya berarti menolak kuitansi yang
 * memang begitu adanya di lembar aslinya, dan impor tidak berwenang
 * membetulkan angka yang sudah tercetak. Konsekuensinya `balance` kuitansi
 * semacam itu bisa negatif — itu memang keadaan yang dilaporkan datanya.
 *
 * Satu grup diproses sebagai satu kesatuan di dalam satu transaksi database:
 * bila ada satu rincian yang ditolak, seluruh kuitansi itu batal. Kuitansi yang
 * separuh terisi akan punya total yang tidak cocok dengan "Dibayar" di filenya,
 * dan selisih itu baru ketahuan jauh di belakang. Grup lain tidak terpengaruh.
 *
 * Dipisah dari TransaksiHeaderController yang sudah panjang: aturan impor
 * (pemetaan kode → id, penggabungan grup, pelaporan per baris) tidak dipakai
 * jalur simpan biasa sama sekali.
 */
class TransaksiImportController extends Controller
{
    use HandlesTransactionRows;

    /**
     * Nama sheet, dikirim ikut tiap baris hasil.
     *
     * Frontend memakainya untuk memisahkan galat ke sheet yang benar saat
     * menulis file "gagal impor" — kesalahan sheet Kuitansi harus mendarat di
     * sheet Kuitansi, bukan tercampur ke daftar rincian.
     */
    private const SHEET_KUITANSI = 'Kuitansi';

    private const SHEET_RINCIAN = 'Rincian';

    /**
     * Baris per perintah INSERT massal.
     *
     * Bukan batas jumlah data — hanya pemotong agar satu pernyataan SQL tidak
     * melampaui `max_allowed_packet` MySQL. Pada lebar baris tabel ini, 1000
     * baris masih jauh di bawah bawaan 16 MB.
     */
    private const UKURAN_SISIP = 1000;

    /**
     * Penanda di peta `terpakai` untuk baris yang BARU disiapkan permintaan ini
     * dan belum punya id — dibedakan dari id kuitansi lama yang bisa ditempeli.
     */
    private const BARU = 'baru';

    /**
     * Kolom sheet "Kuitansi" → kolom tabel `transaction_headers`.
     *
     * Kunci array ini adalah kunci PAYLOAD (yang dikirim frontend), bukan judul
     * kolom di file Excel — judulnya memakai nama kolom database di sebelah
     * kanan, dan frontend yang memetakan keduanya.
     *
     * `potongan_ketua` diisi PERSENTASE (10 = 10%), bukan rupiah.
     */
    private const KOLOM_KUITANSI = [
        // Tanggal uang DITERIMA. Wajib — kuitansi tanpa tanggal tidak bisa
        // dipertanggungjawabkan ke buku kas, dan menebaknya dari waktu impor
        // justru memberi tanggal yang pasti salah untuk data lama.
        'tanggal' => 'date',
        'jenis' => 'transaction_type',
        'dibayar' => 'payment',
        'metode' => 'payment_method',
        'potongan_anggota' => 'member_deduction',
        // Boleh kosong; lihat siapkanGrup() untuk aturan kosong vs terisi.
        'total' => 'total',
        'potongan_ketua' => 'group_leader_fee_percent',
        // Boleh kosong; lihat siapkanGrup() untuk aturan kosong vs terisi.
        'jasa_ketua' => 'group_leader_fee',
        // Nomor kuitansi dari sistem lama. Kosong = server yang membuatkan.
        'no_kuitansi' => 'transaction_number',
    ];

    public function import(Request $request)
    {
        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['array'],
            // Hanya kuitansi yang DIRUJUK baris rincian permintaan ini, bukan
            // seluruh sheet Kuitansi — lihat `indukUntuk()` di frontend.
            'headers' => ['nullable', 'array'],
            'headers.*' => ['array'],
        ], [
            'rows.required' => 'Tidak ada baris rincian yang dikirim.',
            'rows.min' => 'Tidak ada baris rincian yang dikirim.',
        ]);

        // TAHAP 1 — SHEET KUITANSI.
        //
        // Seluruh induk divalidasi lebih dulu, sebelum satu pun baris rincian
        // disentuh. Dua alasannya:
        //
        //  - galat sheet Kuitansi jadi bisa ditunjuk ke BARIS SHEET KUITANSI-nya
        //    sendiri. Sebelumnya validasi induk menumpang di dalam pemrosesan
        //    grup, jadi kesalahan ketik di sheet Kuitansi dilaporkan sebagai
        //    kegagalan baris Rincian — petugas membetulkan sheet yang salah;
        //  - kuitansi yang isinya tidak sah tidak perlu lagi membuka transaksi
        //    database untuk rinciannya.
        $kuitansi = $this->siapkanKuitansi($payload['headers'] ?? []);

        $hasil = [];

        // TAHAP 2 — SHEET RINCIAN, memakai induk yang sudah lolos tahap 1.
        $grupRincian = $this->kelompokkan($payload['rows']);

        // Seluruh anggota, tarif, dan periode-yang-sudah-terpakai ditarik SEKALI
        // untuk permintaan ini, bukan per baris. Lihat muatRujukan().
        $rujukan = $this->muatRujukan($payload['rows']);

        // TAHAP 3 — seluruh grup divalidasi lebih dulu, tanpa satu pun penulisan.
        $siap = [];

        foreach ($grupRincian as $kode => $grup) {
            try {
                $siap[] = $this->siapkanGrup($kode, $grup, $kuitansi, $rujukan);
            } catch (ValidationException $e) {
                $pesan = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                $hasil = array_merge($hasil, $this->gagalkanGrup($grup, $pesan));
            } catch (\Throwable $e) {
                $hasil = array_merge($hasil, $this->gagalkanGrup($grup, $e->getMessage()));
            }
        }

        // TAHAP 4 — yang lolos ditulis sekaligus. Lihat simpanSemua() untuk
        // alasan satu transaksi, dan apa yang ditukar untuk itu.
        if ($siap !== []) {
            try {
                $this->simpanSemua($siap);

                foreach ($siap as $s) {
                    /**
                     * Baris yang sudah ada sebelumnya dilaporkan GAGAL.
                     *
                     * Secara mekanis ia bukan kegagalan — tidak ada yang perlu
                     * dibetulkan, dan datanya memang sudah tersimpan. Tapi
                     * pelaporannya sengaja disatukan ke `gagal` supaya setiap
                     * baris yang TIDAK ikut tertulis pada unggahan ini muncul di
                     * satu daftar yang sama, bisa diekspor, dan tidak ada yang
                     * lolos dari perhatian karena warnanya lebih lunak.
                     *
                     * Pesannya karena itu harus menyebut bahwa datanya aman —
                     * kalau tidak, unggah ulang akan terbaca seperti impor yang
                     * rusak, padahal justru bekerja sebagaimana mestinya.
                     */
                    foreach ($s['lewati'] as $row) {
                        $hasil[] = [
                            'sheet' => self::SHEET_RINCIAN,
                            'baris' => $row['baris'],
                            'status' => 'gagal',
                            'nama' => $this->labelBaris($row),
                            'pesan' => 'tidak ditulis ulang — baris ini sudah ada di aplikasi.',
                        ];
                    }

                    foreach ($s['rincian'] as $baris) {
                        $hasil[] = [
                            'sheet' => self::SHEET_RINCIAN,
                            'baris' => $baris['row']['baris'],
                            'status' => 'ok',
                            'nama' => $this->labelBaris($baris['row']),
                            'pesan' => $s['nomor'] ?? 'ditambahkan ke kuitansi yang sudah ada.',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Penyimpanan batal seluruhnya, jadi TIDAK ADA yang tersimpan
                // dari batch ini — pesannya harus mengatakan itu, kalau tidak
                // petugas mengira sebagiannya sudah masuk dan mengirim ulang
                // hanya sisanya.
                foreach ($siap as $s) {
                    $hasil = array_merge($hasil, $this->gagalkanGrup(
                        $s['grup'],
                        'penyimpanan batch dibatalkan, tidak ada kuitansi batch ini yang tersimpan — '.$e->getMessage()
                    ));
                }
            }
        }

        // Galat sheet Kuitansi dilaporkan sebagai barisnya SENDIRI, bukan hanya
        // menempel pada rinciannya: yang perlu dibetulkan petugas ada di sheet
        // itu, dan tanpa baris ini file "gagal impor" tidak punya tempat untuk
        // menuliskan alasannya.
        //
        // Yang dilaporkan hanya kuitansi yang DIPAKAI baris rincian permintaan
        // ini, ditambah baris tanpa Kode Kuitansi — baris itu tidak mungkin
        // dipakai siapa pun, tapi tetap harus dibetulkan.
        //
        // Frontend sudah menyaring kirimannya ke induk milik batch ini, jadi
        // saringan di sini jarang menggigit. Ia tetap ada karena endpoint-nya
        // tidak boleh bergantung pada kedisiplinan pemanggilnya: kiriman yang
        // memuat lebih banyak induk tetap harus menghasilkan angka `gagal` yang
        // benar, bukan menggelembung sebanyak jumlah batchnya.
        foreach ($kuitansi['galat'] as $galat) {
            if ($galat['kode'] !== '' && ! isset($grupRincian[$galat['kode']])) {
                continue;
            }

            $hasil[] = [
                'sheet' => self::SHEET_KUITANSI,
                'baris' => $galat['baris'],
                'status' => 'gagal',
                'nama' => $galat['kode'],
                'pesan' => $galat['pesan'],
            ];
        }

        // Baris kuitansi kembar. Isinya tidak terpakai, jadi ia masuk daftar
        // gagal bersama baris lain yang tidak tertulis — sekalipun impornya
        // sendiri tetap berhasil untuk kuitansi itu lewat baris kembarannya.
        foreach ($kuitansi['abaikan'] as $abai) {
            if (! isset($grupRincian[$abai['kode']])) {
                continue;
            }

            $hasil[] = [
                'sheet' => self::SHEET_KUITANSI,
                'baris' => $abai['baris'],
                'status' => 'gagal',
                'nama' => $abai['kode'],
                'pesan' => $abai['pesan'],
            ];
        }

        // Urutan hasil mengikuti nomor baris di file, bukan urutan pemrosesan
        // per grup — daftar galat di layar harus bisa ditelusuri turun sejajar
        // dengan filenya. Sheet Kuitansi ditaruh lebih dulu karena itu pula
        // urutan pengerjaannya: betulkan induknya, baru rinciannya.
        usort($hasil, function ($a, $b) {
            $urutan = fn ($h) => $h['sheet'] === self::SHEET_KUITANSI ? 0 : 1;

            return [$urutan($a), $a['baris']] <=> [$urutan($b), $b['baris']];
        });

        $jumlah = array_count_values(array_column($hasil, 'status'));

        // Dua angka saja. Sempat ada `dilewati` sebagai golongan ketiga; kini
        // baris yang tidak tertulis seluruhnya masuk `gagal`, supaya tidak ada
        // yang tersembunyi di golongan yang lebih mudah diabaikan.
        return response()->json([
            'berhasil' => $jumlah['ok'] ?? 0,
            'gagal' => $jumlah['gagal'] ?? 0,
            'hasil' => $hasil,
        ]);
    }

    /**
     * TAHAP 1 — sheet "Kuitansi" divalidasi seluruhnya, sebelum rincian.
     *
     * Hasilnya dua peta yang sama-sama dikunci kode kuitansi:
     *
     *  - `siap`  — kolom `transaction_headers` yang sudah lolos validasi;
     *  - `galat` — kuitansi yang ditolak, beserta NOMOR BARISNYA di sheet
     *    Kuitansi dan alasannya.
     *
     * Kuitansi yang masuk `galat` tidak pernah sampai ke tahap rincian: seluruh
     * rinciannya ikut dibatalkan dengan pesan yang menunjuk balik ke baris sheet
     * Kuitansi yang harus dibetulkan.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array{siap: array<string, array<string, mixed>>, galat: array<string, array{kode: string, baris: int, pesan: string}>, abaikan: array<int, array{kode: string, baris: int, pesan: string}>, adaDiDb: array<string, int>}
     */
    private function siapkanKuitansi(array $headers): array
    {
        $siap = [];
        $galat = [];
        $abaikan = [];
        $barisKode = [];

        foreach ($headers as $i => $row) {
            $baris = (int) ($row['baris'] ?? $i + 2);
            // Nomor kuitansi sekaligus kunci perekat ke sheet Rincian.
            $kode = trim((string) ($row['no_kuitansi'] ?? ''));

            if ($kode === '') {
                $galat['#baris'.$baris] = [
                    'kode' => '',
                    'baris' => $baris,
                    'pesan' => 'transaction_number wajib diisi — nomor itu yang menghubungkan kuitansi ini ke sheet Rincian, sekaligus tersimpan sebagai nomor kuitansinya.',
                ];

                continue;
            }

            /**
             * Nomor kembar di sheet Kuitansi: yang PERTAMA dipakai, sisanya
             * dilewati — bukan ditolak.
             *
             * File migrasi lazim memuat baris kuitansi yang tertulis dua kali,
             * dan menolaknya berarti satu baris kembar menjatuhkan seluruh
             * kuitansi itu berikut semua rinciannya. Nomornya toh sama, jadi
             * yang dituju rinciannya tetap kuitansi yang sama.
             *
             * Dilewati dengan LAPORAN, bukan diam-diam. Dua baris bernomor sama
             * bisa saja berbeda isi — tanggal atau nominal yang tidak sama — dan
             * yang terbuang harus tetap terlihat supaya bisa diperiksa kalau
             * angkanya nanti tidak cocok.
             */
            if (isset($barisKode[$kode])) {
                $abaikan[] = [
                    'kode' => $kode,
                    'baris' => $baris,
                    'pesan' => "transaction_number \"{$kode}\" sudah dipakai baris {$barisKode[$kode]} di sheet Kuitansi. Baris ini dilewati; isian baris {$barisKode[$kode]} yang dipakai.",
                ];

                continue;
            }

            $barisKode[$kode] = $baris;

            try {
                $siap[$kode] = $this->headerDariSheet($row);
            } catch (ValidationException $e) {
                $galat[$kode] = [
                    'kode' => $kode,
                    'baris' => $baris,
                    'pesan' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                ];
            }
        }

        return [
            'siap' => $siap,
            'galat' => $galat,
            // Baris kuitansi kembar yang isinya tidak terpakai. Impornya sendiri
            // tetap jalan lewat baris kembarannya; ini cuma bahan laporan.
            'abaikan' => $abaikan,
            // Nomor yang sudah tersimpan → id kuitansinya. Lihat kuitansiYangSudahAda().
            'adaDiDb' => $this->kuitansiYangSudahAda($siap),
        ];
    }

    /**
     * Cocokkan nomor kuitansi dari file dengan yang SUDAH ADA di database.
     *
     * Nomor yang sudah ada BUKAN bentrok — ia kuitansi yang sama. Sejak nomor
     * itu wajib, unik, dan dibawa file, ia jadi identitas kuitansi yang berlaku
     * lintas unggahan: mengunggah ulang file yang sama harus menemukan kembali
     * kuitansi yang sudah tersimpan, bukan menolaknya sebagai nomor terpakai.
     *
     * Yang dikembalikan peta `nomor => id kuitansi`. Grup yang nomornya ada di
     * peta itu tidak dibuat ulang; rincian yang belum masuk ditempelkan ke
     * kuitansi lamanya, yang sudah ada dilewati.
     *
     * `withTrashed()` supaya cakupannya sama dengan index unik
     * `transaction_number` — kuitansi yang dihapus pun masih memegang nomornya,
     * dan menyisipkan nomor yang sama akan ditolak database.
     *
     * @param  array<string, array<string, mixed>>  $siap  dikunci nomor kuitansi
     * @return array<string, int>
     */
    private function kuitansiYangSudahAda(array $siap): array
    {
        if ($siap === []) {
            return [];
        }

        return TransactionHeader::withTrashed()
            ->whereIn('transaction_number', array_keys($siap))
            ->pluck('id', 'transaction_number')
            ->all();
    }

    /**
     * Kelompokkan rincian berdasarkan `transaction_number`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function kelompokkan(array $rows): array
    {
        $grup = [];

        foreach ($rows as $i => $row) {
            $row['baris'] = (int) ($row['baris'] ?? $i + 1);
            $kode = trim((string) ($row['no_kuitansi'] ?? ''));

            $grup[$kode][] = $row;
        }

        return $grup;
    }

    /**
     * Satu grup rincian + satu baris sheet Kuitansi → satu kuitansi SIAP SIMPAN.
     *
     * Memvalidasi saja; tidak menyentuh database sama sekali. Pemisahan itu yang
     * memungkinkan seluruh grup ditulis dalam satu transaksi di akhir — lihat
     * simpanSemua().
     *
     * Tiga keluaran yang mungkin, tergantung berapa banyak rincian grup ini yang
     * ternyata SUDAH ada di database (dikenali dari anggota + tarif + periode;
     * pada tarif sekali bayar, dari anggota + tarif saja):
     *
     *  - **belum ada satu pun** → kuitansi baru dibuat, seluruh barisnya masuk;
     *  - **sebagian sudah ada** → kuitansi TIDAK dibuat ulang; baris yang belum
     *    masuk ditempelkan ke kuitansi milik baris yang sudah ada;
     *  - **semuanya sudah ada** → tidak ada yang ditulis, seluruh barisnya
     *    dilaporkan "lewati".
     *
     * Yang dihindari cabang kedua: unggahan yang terputus di tengah meninggalkan
     * kuitansi dengan rincian tidak lengkap, dan mengunggah ulang filenya dulu
     * hanya menghasilkan kuitansi KEDUA berisi sisanya — satu pembayaran tercatat
     * sebagai dua kuitansi yang dua-duanya tidak cocok dengan lembar aslinya.
     *
     * @param  array<int, array<string, mixed>>  $grup
     * @param  array<string, array<string, mixed>>  $kuitansi
     * @param  array{anggota: array<string, int>, tarif: array<string, object>, terpakai: array<string, int|string|null>}  $rujukan
     * @return array{kode: string, nomor: ?string, headerId: ?int, header: ?array<string, mixed>, rincian: array<int, array{data: array<string, mixed>, row: array<string, mixed>}>, lewati: array<int, array<string, mixed>>, grup: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function siapkanGrup(
        string $kode,
        array $grup,
        array $kuitansi,
        array &$rujukan
    ): array {
        if ($kode === '') {
            throw ValidationException::withMessages([
                'transaction_number' => 'transaction_number wajib diisi — nomor itu yang menghubungkan rincian ke barisnya di sheet Kuitansi.',
            ]);
        }

        // Induknya sudah ditolak di tahap 1. Pesannya menunjuk balik ke baris
        // sheet Kuitansi supaya petugas membetulkan sheet yang benar — rincian
        // ini sendiri boleh jadi tidak ada salahnya sama sekali.
        if (isset($kuitansi['galat'][$kode])) {
            $galat = $kuitansi['galat'][$kode];

            throw ValidationException::withMessages([
                'transaction_number' => "sheet Kuitansi baris {$galat['baris']} ditolak: {$galat['pesan']}",
            ]);
        }

        if (! isset($kuitansi['siap'][$kode])) {
            throw ValidationException::withMessages([
                'transaction_number' => "transaction_number \"{$kode}\" tidak ada di sheet Kuitansi.",
            ]);
        }

        $header = $kuitansi['siap'][$kode];
        $rincian = array_map(fn ($row) => $this->rincianDariBaris($row, $rujukan), $grup);

        $this->periksaDuplikatDalamGrup($grup, $rincian);

        // Kuitansi ini sudah tersimpan? Nomornya yang menjawab — bukan tebakan
        // dari rincian mana yang kebetulan sudah ada. Lihat kuitansiYangSudahAda().
        $headerLama = $kuitansi['adaDiDb'][$kode] ?? null;

        // Pisahkan rincian yang sudah ada dari yang belum. `array_key_exists`,
        // bukan `isset`: nilainya boleh null — rincian yang tercatat tanpa
        // kuitansi (tagihan yang belum dibayar) tetap terhitung "sudah ada".
        $belum = [];
        $lewati = [];

        foreach ($rincian as $i => $data) {
            if (array_key_exists($this->kunciPeriode($data), $rujukan['terpakai'])) {
                $lewati[] = $grup[$i];

                continue;
            }

            $belum[] = ['data' => $data, 'row' => $grup[$i]];
        }

        // Tidak ada yang perlu ditulis. Kuitansinya TIDAK dibuat ulang.
        if ($belum === []) {
            return [
                'kode' => $kode, 'nomor' => null, 'headerId' => null, 'header' => null,
                'rincian' => [], 'lewati' => $lewati, 'grup' => $grup,
            ];
        }

        /**
         * Kuitansinya sudah ada, rinciannya belum lengkap.
         *
         * Terjadi saat unggahan sebelumnya terputus di tengah, atau saat file
         * yang sama diunggah ulang setelah ditambahi baris. Sisanya ditempelkan
         * ke kuitansi yang ITU — bukan dibuatkan kuitansi kedua berisi sisanya,
         * yang akan mencatat satu pembayaran sebagai dua lembar dan dua-duanya
         * tidak cocok dengan aslinya.
         */
        if ($headerLama !== null) {
            $this->catatTerpakai($belum, $rujukan);

            return [
                'kode' => $kode, 'nomor' => null, 'headerId' => (int) $headerLama, 'header' => null,
                'rincian' => $belum, 'lewati' => $lewati, 'grup' => $grup,
            ];
        }

        // Belum ada sama sekali: kuitansi baru, jalur biasa.
        //
        // Total kuitansi hasil IMPOR diambil dari `payment - member_deduction`,
        // bukan dari jumlah rincian seperti jalur form.
        //
        // Alasannya data migrasi: yang dipercaya di file lama adalah uang yang
        // benar-benar diterima beserta potongannya, sedangkan baris rinciannya
        // kerap tidak lengkap — nominalnya dikosongkan (server memakai harga
        // tarif hari ini) atau periodenya dipadatkan. Menurunkan total dari
        // rincian karenanya menghasilkan angka yang tidak sama dengan lembar
        // kuitansi yang sudah tercetak.
        //
        // `member_deduction` selalu rupiah — templatnya memang tidak punya
        // kolom satuan.
        //
        // Kolom `total` di file dipakai apa adanya bila DIISI, dengan alasan
        // yang sama seperti `group_leader_fee`: angka yang sudah tercetak di
        // kuitansi lama tidak boleh digeser hitungan hari ini.
        $header['total'] = $header['total'] ?? round(
            (float) $header['payment'] - (float) $header['member_deduction'],
            2
        );

        // Ketua kelompok menahan komisinya dari uang yang ia kumpulkan: satu
        // nominal yang sama dicatat sebagai potongan (mengurangi setoran)
        // sekaligus jasa (hak ketua). Hanya potongannya yang masuk `balance`.
        //
        // Nominal dari file dipakai apa adanya bila kolomnya diisi. Data migrasi
        // dari sistem lama menyimpan angka jasanya sendiri, dan menghitung ulang
        // dari persen akan menggesernya — pembulatan saja sudah cukup membuat
        // kuitansi hasil impor tidak lagi cocok dengan lembar yang sudah
        // tercetak, dan selisih sekian rupiah per kuitansi baru ketahuan saat
        // rekapnya tidak imbang.
        //
        // Kolom KOSONG (null) berarti "hitung dari persen", seperti kuitansi yang
        // dibuat lewat form. Nol yang DIKETIK tetap nol — dua keadaan itu sengaja
        // tidak disamakan, karena file migrasi yang memang tidak punya jasa ketua
        // harus bisa menyatakannya tanpa ikut menghapus persentase yang tercatat.
        $nominalJasa = $header['group_leader_fee']
            ?? round($header['total'] * (float) $header['group_leader_fee_percent'] / 100, 2);

        $header['group_leader_deduction'] = $nominalJasa;
        $header['group_leader_fee'] = $nominalJasa;

        $this->catatTerpakai($belum, $rujukan);

        // $kode ADALAH nomor kuitansinya — kunci perekat dan nomor tersimpan kini
        // satu kolom yang sama. Keabsahannya (kosong, kembar di file, sudah ada
        // di database) seluruhnya dijaring siapkanKuitansi() sebelum grup mana
        // pun sampai ke sini.
        //
        // Dilepas dari $header supaya penomoran punya satu jalur: kolomnya
        // dipasang simpanSemua() dari `nomor`, bukan menumpang lewat dua tempat
        // yang bisa berselisih.
        unset($header['transaction_number']);

        return [
            'kode' => $kode,
            'nomor' => $kode,
            'headerId' => null,
            'header' => $header,
            'rincian' => $belum,
            'lewati' => [],
            'grup' => $grup,
        ];
    }

    /**
     * Tandai periode grup ini terpakai, SEKARANG — bukan setelah tersimpan.
     *
     * Penyimpanan baru terjadi di akhir permintaan; menunggu sampai saat itu
     * berarti dua grup berperiode sama sama-sama lolos, lalu ditolak index unik
     * saat penyimpanan — dan yang jatuh justru seluruh batch, bukan salah
     * satunya. Ditandai `BARU` supaya grup berikutnya mengenalinya sebagai
     * "sudah tercakup impor ini" dan tidak mencoba menempel padanya.
     *
     * @param  array<int, array{data: array<string, mixed>}>  $belum
     * @param  array{terpakai: array<string, int|string|null>}  $rujukan
     */
    private function catatTerpakai(array $belum, array &$rujukan): void
    {
        foreach ($belum as $b) {
            $rujukan['terpakai'][$this->kunciPeriode($b['data'])] = self::BARU;
        }
    }

    /**
     * Seluruh kuitansi yang lolos validasi → database, dalam SATU transaksi.
     *
     * Dulu tiap kuitansi punya transaksinya sendiri dan tiap barisnya disimpan
     * lewat `create()` satu per satu. Pada batch 2000 kuitansi itu berarti 2000
     * kali BEGIN/COMMIT — dan setiap COMMIT adalah satu fsync ke disk, sekitar
     * 5 ms, jadi belasan detik per permintaan habis hanya untuk menunggu disk,
     * sebelum menghitung INSERT-nya sendiri. Di sini semuanya jadi dua perintah
     * INSERT massal dan satu commit.
     *
     * Yang HILANG dibanding cara lama: galat tingkat database tidak lagi
     * terbatas pada satu kuitansi — bila penyimpanan gagal, seluruh batch batal.
     * Itu ditukar sadar. Seluruh penolakan yang bisa diperkirakan (anggota tidak
     * ada, periode bentrok, tarif tidak ada, …) sudah dijaring
     * siapkanGrup() sebelum satu baris pun ditulis, sehingga yang tersisa di
     * sini tinggal kegagalan tingkat infrastruktur — dan kegagalan macam itu
     * memang tidak masuk akal disikapi per kuitansi.
     *
     * `insert()` melewati event model, jadi kolom yang biasanya diisi event —
     * `uuid`, `disabled`, dan kolom audit — diisi tangan di sini.
     *
     * Grup yang menempel ke kuitansi yang sudah ada (`header` null) hanya
     * menyumbang rinciannya; tidak ada header baru yang dibuat untuknya.
     *
     * @param  array<int, array{nomor: ?string, headerId: ?int, header: ?array<string, mixed>, rincian: array<int, array{data: array<string, mixed>}>}>  $siap
     */
    private function simpanSemua(array $siap): void
    {
        $sekarang = now();
        $nama = auth()->user()?->name;

        $bawaan = [
            'disabled' => false,
            'created_by' => $nama,
            'updated_by' => $nama,
            'created_at' => $sekarang,
            'updated_at' => $sekarang,
        ];

        DB::transaction(function () use ($siap, $bawaan) {
            $baru = array_values(array_filter($siap, fn ($s) => $s['header'] !== null));

            $headers = array_map(fn ($s) => $s['header'] + [
                'uuid' => (string) Str::uuid(),
                'transaction_number' => $s['nomor'],
            ] + $bawaan, $baru);

            foreach (array_chunk($headers, self::UKURAN_SISIP) as $bagian) {
                TransactionHeader::insert($bagian);
            }

            // Id hasil sisipan dibaca kembali lewat `transaction_number`, yang
            // unik. Menebaknya dari AUTO_INCREMENT hasil INSERT massal memang
            // bisa pada MySQL, tapi hanya di bawah mode penguncian tertentu —
            // satu SELECT jauh lebih murah daripada bergantung pada itu.
            $idPerNomor = $headers === []
                ? collect()
                : TransactionHeader::withTrashed()
                    ->whereIn('transaction_number', array_column($headers, 'transaction_number'))
                    ->pluck('id', 'transaction_number');

            $rincian = [];

            foreach ($siap as $s) {
                // Kuitansi baru memakai id hasil sisipan barusan; grup yang
                // menempel memakai id kuitansi lamanya.
                $headerId = $s['header'] !== null
                    ? $idPerNomor[$s['nomor']]
                    : $s['headerId'];

                foreach ($s['rincian'] as $baris) {
                    $rincian[] = $baris['data'] + [
                        'transaction_header_id' => $headerId,
                        'uuid' => (string) Str::uuid(),
                    ] + $bawaan;
                }
            }

            foreach (array_chunk($rincian, self::UKURAN_SISIP) as $bagian) {
                Transaction::insert($bagian);
            }
        });
    }

    /**
     * Tandai seluruh baris satu grup gagal.
     *
     * Baris yang tidak bersalah tetap diberi tahu alasannya menyebut baris mana
     * yang bermasalah — tanpa itu, pengguna melihat baris yang tampak benar
     * ditolak tanpa sebab dan mengira impornya rusak.
     *
     * @param  array<int, array<string, mixed>>  $grup
     * @return array<int, array<string, mixed>>
     */
    private function gagalkanGrup(array $grup, string $pesan): array
    {
        $kode = trim((string) ($grup[0]['no_kuitansi'] ?? ''));
        $satuBaris = count($grup) === 1 || $kode === '';

        return array_map(function ($row) use ($pesan, $kode, $satuBaris) {
            return [
                'sheet' => self::SHEET_RINCIAN,
                'baris' => $row['baris'],
                'status' => 'gagal',
                'nama' => $this->labelBaris($row),
                'pesan' => $satuBaris
                    ? $pesan
                    : "Kuitansi \"{$kode}\" batal seluruhnya — {$pesan}",
            ];
        }, $grup);
    }

    /** Penanda baris di daftar hasil: nomor anggota + periodenya. */
    private function labelBaris(array $row): string
    {
        $bagian = array_filter([
            trim((string) ($row['no_anggota'] ?? '')),
            trim((string) ($row['periode'] ?? '')),
        ]);

        return implode(' · ', $bagian);
    }

    /**
     * Satu baris sheet "Kuitansi" → kolom `transaction_headers` siap simpan.
     *
     * `metode` & `jenis` dinormalkan ke huruf kecil sebelum divalidasi: petugas
     * lazim mengetik "Cash" atau "Pribadi" dengan huruf besar, dan menolak itu
     * sebagai nilai tidak sah hanya bikin bingung.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function headerDariSheet(array $row): array
    {
        $data = [];

        foreach (self::KOLOM_KUITANSI as $kolomFile => $kolomDb) {
            $nilai = $this->bersihkan($row[$kolomFile] ?? null);
            $data[$kolomDb] = ($nilai === '') ? null : $nilai;
        }

        $data['payment_method'] = mb_strtolower((string) $data['payment_method']);
        $data['transaction_type'] = mb_strtolower((string) $data['transaction_type']);

        $validator = Validator::make($data, [
            'date' => ['required', 'date'],
            'transaction_type' => ['required', Rule::in(TransactionHeader::TRANSACTION_TYPES)],
            'payment' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'payment_method' => ['required', Rule::in(TransactionHeader::PAYMENT_METHODS)],
            'member_deduction' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            // Sengaja TIDAK diberi nilai bawaan: `null` (kolom dikosongkan)
            // berarti "hitung dari payment − potongan", sedangkan nol yang
            // DIKETIK tetap nol. Yang membedakannya siapkanGrup().
            'total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'group_leader_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Sengaja TIDAK diberi nilai bawaan di sini: `null` (kolom
            // dikosongkan) dan `0` (nol yang diketik) punya arti berbeda, dan
            // yang membedakannya adalah siapkanGrup().
            'group_leader_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            /**
             * Nomor kuitansi dari file — WAJIB, dipakai apa adanya.
             *
             * Wajib karena ia merangkap dua peran: nomor yang tersimpan, DAN
             * kunci yang menyambungkan kuitansi ini ke baris-barisnya di sheet
             * Rincian. Yang kosong tidak punya apa pun untuk disambungkan.
             *
             * Bentuknya tidak dipaksa mengikuti YYMMDD + urut: nomor lama adalah
             * fakta yang sudah terjadi, dan aturan penomoran hari ini tidak
             * berlaku surut untuknya. Yang dijaga cuma panjang kolomnya.
             */
            'transaction_number' => ['required', 'string', 'max:50'],
        ], [
            'date.required' => 'date wajib diisi (format YYYY-MM-DD, mis. 2026-08-23).',
            'date.date' => 'date tidak dikenali. Pakai format YYYY-MM-DD, mis. 2026-08-23.',
            'transaction_type.required' => 'transaction_type wajib diisi (kelompok / pribadi).',
            'transaction_type.in' => 'transaction_type hanya boleh "kelompok" atau "pribadi".',
            'payment.required' => 'payment wajib diisi.',
            'payment.numeric' => 'payment harus berupa angka.',
            'payment_method.required' => 'payment_method wajib diisi (cash / transfer / other).',
            'payment_method.in' => 'payment_method hanya boleh "cash", "transfer", atau "other".',
            'numeric' => ':attribute harus berupa angka.',
            'min' => ':attribute tidak boleh negatif.',
            'group_leader_fee_percent.max' => 'group_leader_fee_percent tidak boleh lebih dari 100 (satuannya persen, bukan rupiah).',
        ], [
            // Nama atribut dipetakan ke dirinya sendiri supaya `:attribute`
            // tercetak persis seperti JUDUL KOLOM di file. Tanpa ini Laravel
            // memanusiakan snake_case jadi "group leader fee", dan petugas
            // mencari kolom bernama itu di Excel-nya — yang tidak ada.
            'date' => 'date',
            'transaction_type' => 'transaction_type',
            'payment' => 'payment',
            'payment_method' => 'payment_method',
            'member_deduction' => 'member_deduction',
            'total' => 'total',
            'group_leader_fee_percent' => 'group_leader_fee_percent',
            'group_leader_fee' => 'group_leader_fee',
            'transaction_number' => 'transaction_number',
        ]);

        $validator->stopOnFirstFailure();
        $data = $validator->validate();

        $data['member_deduction'] = $data['member_deduction'] ?? 0;
        $data['group_leader_fee_percent'] = $data['group_leader_fee_percent'] ?? 0;

        // Sama dengan jalur simpan biasa: potongan & jasa ketua kelompok hanya
        // berlaku pada setoran kelompok, jadi dinolkan pada kuitansi pribadi
        // alih-alih diam-diam menggeser `balance`.
        if ($data['transaction_type'] === 'pribadi') {
            $data['group_leader_fee_percent'] = 0;
            // Dinolkan, bukan dibiarkan null: null berarti "hitung dari
            // persen", dan pada kuitansi pribadi jawabannya harus pasti nol
            // bahkan bila filenya terlanjur mengisi nominal jasa.
            $data['group_leader_fee'] = 0;
        }

        return $data;
    }

    /**
     * Satu baris file → satu rincian siap simpan.
     *
     * Anggota & tarif dirujuk lewat kode yang dilihat petugas di aplikasi
     * (`No. Anggota`, `Kode Tarif`), bukan id database — id tidak pernah tampil
     * di layar mana pun, jadi tidak ada cara wajar mengisinya dari file.
     *
     * @param  array{anggota: array<string, int>, tarif: array<string, object>, terpakai: array<string, true>}  $rujukan
     * @return array<string, mixed>
     */
    private function rincianDariBaris(array $row, array $rujukan): array
    {
        $noAnggota = (string) $this->bersihkan($row['no_anggota'] ?? null);
        $kodeTarif = (string) $this->bersihkan($row['kode_tarif'] ?? null);
        $field = 'baris'.$row['baris'];

        if ($noAnggota === '') {
            throw ValidationException::withMessages([$field => "baris {$row['baris']}: member_number wajib diisi."]);
        }

        if ($kodeTarif === '') {
            throw ValidationException::withMessages([$field => "baris {$row['baris']}: rate_code wajib diisi."]);
        }

        $memberId = $rujukan['anggota'][$noAnggota] ?? null;

        if ($memberId === null) {
            throw ValidationException::withMessages([
                $field => "baris {$row['baris']}: anggota dengan member_number \"{$noAnggota}\" tidak ada di master.",
            ]);
        }

        $rate = $rujukan['tarif'][$kodeTarif] ?? null;

        if ($rate === null) {
            throw ValidationException::withMessages([
                $field => "baris {$row['baris']}: tarif dengan rate_code \"{$kodeTarif}\" tidak ada di master.",
            ]);
        }

        $nominal = $this->bersihkan($row['nominal'] ?? null);

        // Nominal kosong diisi harga tarifnya. Iuran rutin nominalnya selalu
        // sama dengan masternya, jadi mewajibkan kolom ini hanya memaksa
        // petugas menyalin angka yang sama ratusan kali — dan tiap salinan
        // adalah kesempatan salah ketik.
        if ($nominal === null || $nominal === '') {
            $nominal = $rate->price;
        }

        $diskon = $this->bersihkan($row['diskon'] ?? null);
        $diskon = ($diskon === null || $diskon === '') ? 0 : $diskon;

        $validator = Validator::make(
            ['amount' => $nominal, 'discount' => $diskon],
            [
                'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
                'discount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            ],
            [
                'amount.numeric' => "baris {$row['baris']}: amount harus berupa angka.",
                'discount.numeric' => "baris {$row['baris']}: discount harus berupa angka.",
                'min' => "baris {$row['baris']}: :attribute tidak boleh negatif.",
            ],
            ['amount' => 'amount', 'discount' => 'discount']
        );

        $validator->stopOnFirstFailure();
        $angka = $validator->validate();

        $periode = $this->bersihkan($row['periode'] ?? null);
        $periode = ($periode === null || $periode === '') ? null : (string) $periode;

        /**
         * Periode diambil APA ADANYA dari file. `fee_type` tarifnya TIDAK
         * dilihat sama sekali di jalur impor.
         *
         * Dulu di sini berlaku aturan master: tarif `one_time` periodenya
         * dibuang, tarif berulang wajib berperiode. Yang jadi masalah adalah
         * cabang pertamanya. Satu tarif salah klasifikasi di master sudah cukup
         * untuk membuang periode SELURUH barisnya, dan begitu periodenya hilang
         * kunci pembandingnya menyusut jadi "anggota-tarif" saja — dua belas
         * baris bulanan seorang anggota mengerucut jadi satu, sebelas sisanya
         * dilaporkan "sudah ada, dilewati". Tidak ada galat, tidak ada tanda:
         * datanya lenyap diam-diam dan baru ketahuan saat jumlahnya kurang.
         *
         * File migrasi tahu periode tiap barisnya; klasifikasi di master belum
         * tentu benar untuk data bertahun-tahun ke belakang. Yang dipercaya
         * karena itu file, bukan master.
         *
         * Bentuk "MM/YYYY" tetap diperiksa `pecahPeriode()` — itu pemeriksaan
         * FORMAT, bukan klasifikasi tarif. Periode salah ketik harus tetap
         * dilaporkan, bukan diam-diam disimpan sebagai kosong.
         *
         * Jalur simpan lewat form TIDAK berubah: di sana `fee_type` menentukan
         * field periodenya muncul atau tidak, dan aturannya masih dijaga
         * `periodeUntukTarif()` di trait.
         */
        $data = [
            'member_id' => (int) $memberId,
            'amount' => $angka['amount'],
            'discount' => $angka['discount'],
            'rate_id' => (int) $rate->id,
        ] + ($periode === null
            ? ['month' => null, 'year' => null]
            : $this->pecahPeriode($periode, $field));

        $this->periksaDiskon($data, $field);

        // Bentrok dengan data yang sudah tersimpan TIDAK diperiksa di sini lagi.
        // Baris semacam itu bukan kesalahan yang perlu dibetulkan, melainkan
        // baris yang sudah masuk pada unggahan sebelumnya — siapkanGrup() yang
        // memisahkannya sebagai "dilewati".
        return $data;
    }

    /**
     * Anggota, tarif, dan periode-yang-sudah-terpakai untuk SELURUH permintaan,
     * ditarik dalam tiga query.
     *
     * Sebelumnya tiap baris rincian menembak database empat kali: cari anggota,
     * cari tarif, baca `fee_type` tarif itu lagi, lalu cek bentrok periode. Pada
     * file 299.562 baris itu berarti sekitar 1,2 juta query — dan itulah yang
     * memaksa batch-nya dipotong kecil-kecil, bukan besaran datanya sendiri.
     * Dengan rujukan yang dimuat sekali, biaya per baris tinggal pencarian di
     * array plus INSERT-nya.
     *
     * `terpakai` dibaca lewat `cursor()`: seorang anggota bisa punya ratusan
     * baris riwayat, dan memuat seluruh modelnya sekaligus justru memindahkan
     * masalahnya dari jumlah query ke pemakaian memori.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{anggota: array<string, int>, tarif: array<string, object>, terpakai: array<string, true>}
     */
    private function muatRujukan(array $rows): array
    {
        $noAnggota = [];
        $kodeTarif = [];

        foreach ($rows as $row) {
            $a = trim((string) ($row['no_anggota'] ?? ''));
            $t = trim((string) ($row['kode_tarif'] ?? ''));

            if ($a !== '') {
                $noAnggota[$a] = true;
            }

            if ($t !== '') {
                $kodeTarif[$t] = true;
            }
        }

        // Cakupan query sengaja dibiarkan sama dengan versi per-baris yang
        // digantikannya (global scope `active` ikut berlaku), supaya baris yang
        // dulu ditolak "tidak ada di master" tetap ditolak dengan alasan sama.
        $anggota = $noAnggota === []
            ? []
            : Member::whereIn('member_number', array_keys($noAnggota))
                ->pluck('id', 'member_number')
                ->all();

        $tarif = $kodeTarif === []
            ? []
            : Rate::whereIn('code', array_keys($kodeTarif))
                ->get(['id', 'code', 'price'])
                ->keyBy('code')
                ->all();

        // Nilainya id KUITANSI pemilik baris itu, bukan sekadar penanda ada:
        // rincian yang belum masuk perlu ditempelkan ke kuitansi yang sama, dan
        // di sinilah satu-satunya tempat id itu bisa diketahui.
        //
        // `whereNotNull('month')` sengaja dilepas. Baris tarif sekali bayar
        // ber-`month`/`year` NULL, dan kunciPeriode() memampatkannya jadi
        // "anggota-tarif--" — persis kunci yang diminta: sekali bayar hanya
        // boleh satu per anggota per tarif. Selama baris itu tidak dimuat,
        // unggah ulang tidak bisa mengenalinya dan menggandakannya diam-diam.
        $terpakai = [];

        if ($anggota !== []) {
            Transaction::withTrashed()
                ->whereIn('member_id', array_values($anggota))
                ->select(['member_id', 'rate_id', 'month', 'year', 'transaction_header_id'])
                ->cursor()
                ->each(function ($baris) use (&$terpakai) {
                    $terpakai[$this->kunciPeriode([
                        'member_id' => $baris->member_id,
                        'rate_id' => $baris->rate_id,
                        'month' => $baris->month,
                        'year' => $baris->year,
                    ])] = $baris->transaction_header_id;
                });
        }

        return ['anggota' => $anggota, 'tarif' => $tarif, 'terpakai' => $terpakai];
    }

    /**
     * Bentrok DI DALAM satu grup — tidak akan tertangkap pemeriksaan database
     * karena barisnya belum tersimpan saat baris berikutnya diperiksa.
     *
     * @param  array<int, array<string, mixed>>  $grup
     * @param  array<int, array<string, mixed>>  $rincian
     */
    private function periksaDuplikatDalamGrup(array $grup, array $rincian): void
    {
        $terpakai = [];

        foreach ($rincian as $i => $baris) {
            // Baris tarif sekali bayar IKUT diperiksa sekarang.
            //
            // Dulu dilewati, dengan alasan pungutan sekali bayar boleh dicatat
            // berulang. Aturannya kini dibalik: satu anggota hanya membayar satu
            // pungutan sekali bayar satu kali, jadi dua barisnya di kuitansi yang
            // sama adalah salah ketik. Tanpa pemeriksaan ini kuncinya juga tidak
            // punya arti apa-apa, dan unggah ulang tidak bisa mengenali baris
            // sekali bayar yang sudah masuk.
            $kunci = $this->kunciPeriode($baris);

            if (isset($terpakai[$kunci])) {
                throw ValidationException::withMessages([
                    'grup' => "baris {$grup[$i]['baris']} mengulang anggota, tarif, dan periode yang sama dengan baris {$terpakai[$kunci]}.",
                ]);
            }

            $terpakai[$kunci] = $grup[$i]['baris'];
        }
    }

    /** Sel Excel kosong, spasi, dan angka dinormalkan jadi satu bentuk. */
    private function bersihkan(mixed $nilai): mixed
    {
        return is_string($nilai) ? trim($nilai) : $nilai;
    }
}
