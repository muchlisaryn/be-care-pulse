<?php

namespace App\Traits;

use App\Models\Rate;
use App\Models\Transaction;
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
     * | `one_time`            | harus kosong     | `month`/`year` = null  |
     * | `recurring` atau NULL | wajib diisi      | `month`/`year` terisi  |
     *
     * `fee_type` yang masih NULL diperlakukan sebagai `recurring`: itu keadaan
     * tarif lama yang belum diklasifikasi, dan sebelum kolom `fee_type` ada
     * semua tarif memang berperiode. Menganggapnya sekali bayar akan diam-diam
     * membuang periode pada data yang sudah berjalan.
     */
    protected function periodeUntukTarif(?string $periode, int $rateId, string $field): array
    {
        $sekaliBayar = Rate::whereKey($rateId)->value('fee_type') === Rate::FEE_TYPE_ONE_TIME;

        if ($sekaliBayar) {
            if (filled($periode)) {
                throw ValidationException::withMessages([
                    $field => 'Tarif sekali bayar tidak memakai periode.',
                ]);
            }

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
            throw ValidationException::withMessages([
                $field => 'Anggota ini sudah punya transaksi untuk tarif dan periode tersebut.',
            ]);
        }
    }

    /** Total satu baris setelah potongan, tidak pernah negatif. */
    protected function totalBaris(array $baris): float
    {
        return max(0, (float) $baris['amount'] - (float) ($baris['discount'] ?? 0));
    }
}
