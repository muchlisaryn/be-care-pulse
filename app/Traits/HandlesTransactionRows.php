<?php

namespace App\Traits;

use App\Models\Transaction;
use Carbon\Carbon;
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
     * "MM/YYYY" → tanggal 1 bulan itu.
     *
     * Periode disimpan sebagai DATE agar bisa diurutkan dan difilter sebagai
     * rentang; harinya dipatok ke 1 supaya dua baris periode yang sama tidak
     * lolos dari index unik hanya karena berbeda hari.
     */
    protected function awalBulan(string $periode, string $field): string
    {
        if (! preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', trim($periode), $m)) {
            throw ValidationException::withMessages([
                $field => 'Periode harus berformat MM/YYYY, contoh 08/2026.',
            ]);
        }

        return Carbon::createFromDate((int) $m[2], (int) $m[1], 1)->toDateString();
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
     */
    protected function periksaDuplikatPeriode(
        array $baris,
        string $field,
        ?int $abaikanId = null
    ): void {
        $bentrok = Transaction::withTrashed()
            ->where('member_id', $baris['member_id'])
            ->where('rate_id', $baris['rate_id'])
            ->where('payment_period', $baris['payment_period'])
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
