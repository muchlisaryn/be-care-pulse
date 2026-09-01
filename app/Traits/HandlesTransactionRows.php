<?php

namespace App\Traits;

use App\Models\Rate;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Aturan baris rincian transaksi iuran, dipakai bersama oleh dua jalur simpan:
 * satuan (`TransaksiController`) dan massal bersama header
 * (`TransaksiHeaderController`).
 *
 * Diletakkan di satu tempat supaya aturannya tidak pernah berbeda di antara
 * keduanya — baris yang ditolak lewat form satuan harus juga ditolak saat
 * dikirim sebagai bagian dari kuitansi.
 */
trait HandlesTransactionRows
{
    /**
     * "MM/YYYY" → `['month' => 8, 'year' => 2026]`.
     *
     * Periode disimpan sebagai dua kolom integer, bukan satu DATE: tarif sekali
     * bayar (`fee_type = one_time`) tidak punya periode sama sekali, dan dengan
     * kolom DATE keadaan itu hanya bisa diwakili tanggal karangan.
     */
    protected function pecahPeriode(string $periode, string $field): array
    {
        if (! preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', trim($periode), $m)) {
            throw ValidationException::withMessages([
                $field => 'Periode harus berformat MM/YYYY, contoh 08/2026.',
            ]);
        }

        return ['month' => (int) $m[1], 'year' => (int) $m[2]];
    }

    /**
     * Terjemahkan `payment_period` dari request menjadi kolom `month` & `year`,
     * dengan aturan yang ditentukan `fee_type` tarifnya.
     *
     * | `fee_type` tarif      | `payment_period` | Hasil                  |
     * | --------------------- | ---------------- | ---------------------- |
     * | `one_time`            | diabaikan        | `month`/`year` = null  |
     * | `recurring` atau NULL | wajib diisi      | `month`/`year` terisi  |
     *
     * `fee_type` yang masih NULL diperlakukan sebagai `recurring`: itu keadaan
     * tarif lama yang belum diklasifikasi, dan sebelum kolom `fee_type` ada
     * semua tarif memang berperiode. Menganggapnya sekali bayar akan diam-diam
     * membuang periode pada data yang sudah berjalan.
     */
    protected function periodeUntukTarif(?string $periode, int $rateId, string $field): array
    {
        return $this->periodeUntukSifatTarif(
            $periode,
            Rate::whereKey($rateId)->value('fee_type'),
            $field
        );
    }

    /**
     * Sama dengan `periodeUntukTarif()`, tapi sifat tarifnya DIBERIKAN, bukan
     * dibaca ulang dari database.
     *
     * Dipakai impor massal, yang sudah memuat seluruh tarif rujukan satu kali di
     * awal permintaan. Lewat `periodeUntukTarif()` setiap baris menambah satu
     * query hanya untuk mengambil kolom yang sudah ada di tangan — pada file
     * ratusan ribu baris, biaya itu saja sudah lebih besar daripada seluruh
     * penyimpanannya.
     *
     * Aturannya sengaja tetap di sini, bukan disalin ke pemanggilnya: baris yang
     * ditolak lewat form satuan harus juga ditolak lewat impor.
     */
    protected function periodeUntukSifatTarif(?string $periode, ?string $feeType, string $field): array
    {
        $sekaliBayar = $feeType === Rate::FEE_TYPE_ONE_TIME;

        // Periode pada tarif sekali bayar DIKOSONGKAN, bukan ditolak.
        //
        // Dulu ini melempar "Tarif sekali bayar tidak memakai periode". Yang
        // ditolaknya bukan data yang salah, melainkan data yang berlebih: kolom
        // itu memang tidak punya arti untuk pungutan sekali bayar, dan sudah ada
        // jawaban yang benar untuknya — null. Menolak justru memaksa pengirimnya
        // mengosongkan sel satu per satu untuk sampai ke hasil yang sama, dan
        // pada file migrasi (sistem lama mencatat periode pada SEMUA baris) itu
        // berarti ratusan ribu baris ditolak tanpa satu pun benar-benar salah.
        if ($sekaliBayar) {
            return ['month' => null, 'year' => null];
        }

        if (blank($periode)) {
            throw ValidationException::withMessages([
                $field => 'Periode pembayaran wajib diisi untuk tarif berulang.',
            ]);
        }

        return $this->pecahPeriode($periode, $field);
    }

    /**
     * Diskon melebihi nominal menghasilkan tagihan negatif — hampir selalu
     * salah ketik, dan lebih baik ditolak daripada tersimpan diam-diam.
     */
    protected function periksaDiskon(array $baris, string $field): void
    {
        if ((float) ($baris['discount'] ?? 0) > (float) $baris['amount']) {
            throw ValidationException::withMessages([
                $field => 'Diskon tidak boleh melebihi nominal.',
            ]);
        }
    }

    /**
     * Satu anggota hanya boleh punya satu baris per tarif per periode.
     *
     * Cakupannya `withTrashed()` supaya sama dengan index unik di database.
     * Kalau lebih sempit, baris lolos validasi lalu gagal dengan galat SQL
     * mentah yang tidak menyebut penyebabnya.
     *
     * Baris tanpa periode (tarif sekali bayar) dilewati: index unik di database
     * pun tidak membatasinya karena NULL tidak pernah sama dengan NULL, jadi
     * memeriksanya di sini justru melarang hal yang sengaja diizinkan —
     * pungutan sekali bayar boleh dicatat berkali-kali.
     */
    protected function periksaDuplikatPeriode(
        array $baris,
        string $field,
        ?int $abaikanId = null
    ): void {
        if ($baris['month'] === null || $baris['year'] === null) {
            return;
        }

        $bentrok = Transaction::withTrashed()
            ->where('member_id', $baris['member_id'])
            ->where('rate_id', $baris['rate_id'])
            ->where('month', $baris['month'])
            ->where('year', $baris['year'])
            ->when($abaikanId, fn ($q) => $q->where('id', '!=', $abaikanId))
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([$field => $this->pesanDuplikatPeriode()]);
        }
    }

    /**
     * Kunci gabungan anggota + tarif + periode — bentuk yang sama dengan index
     * unik `transactions_unik` di database.
     *
     * Impor massal memakainya untuk memeriksa bentrok lewat daftar yang sudah
     * dimuat di memori, tanpa satu query per baris. Bentuknya diletakkan di sini
     * supaya pemeriksaan itu tidak bisa memakai kunci yang berbeda dari yang
     * dipakai `periksaDuplikatDalamGrup()`.
     */
    protected function kunciPeriode(array $baris): string
    {
        return $baris['member_id'].'-'.$baris['rate_id'].'-'.$baris['month'].'-'.$baris['year'];
    }

    /**
     * Satu kalimat penolakan untuk periode yang sudah terpakai.
     *
     * Dipakai bersama jalur DB (`periksaDuplikatPeriode`) dan jalur memori di
     * impor massal — dua pemeriksaan yang sama harus menolak dengan kalimat yang
     * sama, kalau tidak petugas mengira keduanya masalah berbeda.
     */
    protected function pesanDuplikatPeriode(): string
    {
        return 'Anggota ini sudah punya transaksi untuk tarif dan periode tersebut.';
    }

    /** Total satu baris setelah potongan, tidak pernah negatif. */
    protected function totalBaris(array $baris): float
    {
        return max(0, (float) $baris['amount'] - (float) ($baris['discount'] ?? 0));
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
     * Baris tanpa periode (tarif sekali bayar) otomatis tersaring keluar --
     * perbandingan apa pun terhadap NULL bernilai NULL, bukan true. Itu memang
     * yang diinginkan: baris yang tidak punya periode tidak berada di dalam
     * rentang periode mana pun.
     *
     * `$tabel` diisi HANYA bila query-nya mengandung join -- tanpa kualifikasi,
     * `year`/`month` jadi ambigu begitu ada tabel tetangga yang punya kolom
     * senama.
     *
     * @param  array{month:int,year:int}  $batas
     * @param  '>='|'<='  $arah
     */
    protected function filterRentangPeriode(
        Builder $query,
        array $batas,
        string $arah,
        ?string $tabel = null
    ): void {
        $tahun = $tabel ? "{$tabel}.year" : 'year';
        $bulan = $tabel ? "{$tabel}.month" : 'month';
        $tahunLebih = $arah === '>=' ? '>' : '<';

        $query->where(function (Builder $q) use ($batas, $arah, $tahunLebih, $tahun, $bulan) {
            $q->where($tahun, $tahunLebih, $batas['year'])
                ->orWhere(function (Builder $q) use ($batas, $arah, $tahun, $bulan) {
                    $q->where($tahun, $batas['year'])
                        ->where($bulan, $arah, $batas['month']);
                });
        });
    }
}
