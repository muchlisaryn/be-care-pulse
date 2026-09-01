<?php

namespace App\Support;

/**
 * Angka rupiah → kalimat terbilang bahasa Indonesia.
 *
 * Ditaruh di Support, bukan di controller, karena terbilang adalah hal yang
 * cepat dibutuhkan di banyak tempat sekaligus (daftar jasa ketua, kuitansi,
 * biling PDF) dan jawabannya harus SAMA di semuanya — kalimat yang berbeda
 * untuk angka yang sama pada dua dokumen adalah temuan audit, bukan selera.
 */
class Terbilang
{
    private const SATUAN = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    /**
     * Bentuk lengkap berakhiran "rupiah", mis. 1500 → "seribu lima ratus rupiah".
     *
     * Pecahan sen DIBULATKAN, bukan dieja: kuitansi Nafsul tidak pernah dibayar
     * dalam sen, dan "nol koma lima nol sen" hanya bikin baris terbilang panjang
     * tanpa menambah kepastian apa pun.
     */
    public static function rupiah(float|int|string $nilai): string
    {
        $angka = (int) round((float) $nilai);

        if ($angka < 0) {
            return 'minus '.self::eja(abs($angka)).' rupiah';
        }

        return self::eja($angka).' rupiah';
    }

    /**
     * Ejaan angka tanpa satuan mata uang. 0 tetap dieja "nol" — dipakai sebagai
     * potongan oleh pemanggil lain, jadi tidak boleh mengembalikan string kosong.
     */
    public static function eja(int $angka): string
    {
        if ($angka < 0) {
            return 'minus '.self::eja(abs($angka));
        }

        if ($angka === 0) {
            return 'nol';
        }

        return self::susun($angka);
    }

    /**
     * Rekursi per kelipatan seribu. "se-" khusus dipakai saat pengalinya tepat
     * satu (seratus, seribu), bukan "satu ratus" / "satu ribu".
     */
    private static function susun(int $angka): string
    {
        if ($angka < 12) {
            return self::SATUAN[$angka];
        }

        if ($angka < 20) {
            return self::susun($angka - 10).' belas';
        }

        if ($angka < 100) {
            $sisa = $angka % 10;

            return self::susun(intdiv($angka, 10)).' puluh'.($sisa ? ' '.self::susun($sisa) : '');
        }

        if ($angka < 200) {
            $sisa = $angka - 100;

            return 'seratus'.($sisa ? ' '.self::susun($sisa) : '');
        }

        if ($angka < 1000) {
            $sisa = $angka % 100;

            return self::susun(intdiv($angka, 100)).' ratus'.($sisa ? ' '.self::susun($sisa) : '');
        }

        if ($angka < 2000) {
            $sisa = $angka - 1000;

            return 'seribu'.($sisa ? ' '.self::susun($sisa) : '');
        }

        foreach ([1_000_000_000_000 => 'triliun', 1_000_000_000 => 'miliar', 1_000_000 => 'juta', 1000 => 'ribu'] as $nilai => $nama) {
            if ($angka >= $nilai) {
                $sisa = $angka % $nilai;

                return self::susun(intdiv($angka, $nilai)).' '.$nama.($sisa ? ' '.self::susun($sisa) : '');
            }
        }

        return self::SATUAN[$angka] ?? (string) $angka;
    }
}
