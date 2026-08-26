<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kerangka impor Excel per baris untuk master Nafsul.
 *
 * Frontend membaca file Excel-nya sendiri lalu mengirim maksimal 50 baris per
 * permintaan, supaya progres "x dari y" bisa ditampilkan dan file besar tidak
 * diproses sekaligus.
 *
 * Yang diseragamkan di sini hanya alur perulangannya — validasi dan penyimpanan
 * tiap baris tetap milik controller masing-masing, lewat callback `$simpanBaris`.
 */
trait ImportsExcelRows
{
    /**
     * @param  callable(array): array  $simpanBaris  Menyimpan satu baris; mengembalikan
     *                                               field tambahan untuk hasil baris (mis. `kode`, `nama`).
     *                                               Melempar ValidationException bila baris tidak valid.
     */
    protected function prosesImport(Request $request, callable $simpanBaris): JsonResponse
    {
        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*' => ['array'],
        ]);

        $hasil = [];
        $berhasil = 0;

        foreach ($payload['rows'] as $i => $row) {
            // `baris` = nomor baris di file Excel, dikirim FE supaya pesan
            // kesalahan menunjuk ke baris yang benar di file aslinya.
            $baris = (int) ($row['baris'] ?? $i + 1);
            $nama = trim((string) ($row['nama'] ?? ''));

            try {
                // Tiap baris berdiri sendiri: satu baris gagal tidak
                // membatalkan baris lain dalam batch yang sama.
                $hasil[] = ['baris' => $baris, 'status' => 'ok'] + $simpanBaris($row);
                $berhasil++;
            } catch (ValidationException $e) {
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'gagal',
                    'nama' => $nama,
                    'pesan' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'gagal',
                    'nama' => $nama,
                    'pesan' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'berhasil' => $berhasil,
            'gagal' => count($hasil) - $berhasil,
            'hasil' => $hasil,
        ]);
    }

    /**
     * Ambil kolom yang diminta dari satu baris Excel, rapikan spasinya, dan
     * samakan sel kosong menjadi null.
     *
     * Sel kosong di Excel bisa datang sebagai string kosong maupun spasi saja;
     * tanpa penyeragaman ini aturan `required` akan meloloskannya.
     */
    protected function ambilKolom(array $row, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

        return $data;
    }
}
