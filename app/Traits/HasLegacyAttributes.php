<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Menjaga bentuk API tetap sama setelah kolom & tabel database diganti ke
 * bahasa Inggris.
 *
 * Skema database modul Nafsul dirapikan (tabel & kolom bahasa Inggris, kolom
 * relasi memakai pola `<tabel>_id`), tetapi kontrak API — nama field JSON dan
 * nama field yang dikirim frontend — sengaja dipertahankan apa adanya supaya
 * frontend tidak perlu diubah massal.
 *
 * Model yang memakai trait ini mendeklarasikan dua peta:
 *
 *   protected static array $legacyAttributes = [
 *       'nama' => 'name',                     // rename biasa
 *       'kode_wilayah' => [                   // kode lama ↔ id baru
 *           'column' => 'region_id',
 *           'relation' => 'region',
 *           'key' => 'code',
 *       ],
 *   ];
 *
 *   protected static array $legacyRelations = ['wilayah' => 'region'];
 *
 * Arah baca ditangani `toArray()`, arah tulis oleh `fromLegacy()`.
 */
trait HasLegacyAttributes
{
    /** @return array<string, string|array{column:string,relation:string,key:string}> */
    public static function legacyAttributes(): array
    {
        return static::$legacyAttributes ?? [];
    }

    /** @return array<string, string> nama relasi lama → nama relasi baru */
    public static function legacyRelations(): array
    {
        return static::$legacyRelations ?? [];
    }

    /**
     * Serialisasi memakai nama lama.
     *
     * Kolom baru yang punya padanan lama dibuang dari hasil supaya response
     * benar-benar identik dengan sebelum refactor — bukan gabungan keduanya.
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $hasil = [];

        $kolomBaru = [];
        foreach (static::legacyAttributes() as $definisi) {
            $kolomBaru[] = is_array($definisi) ? $definisi['column'] : $definisi;
        }

        // Eloquent menulis relasi dengan snake_case (`groupLeader` → `group_leader`),
        // jadi kedua bentuknya harus dikenali.
        $relasiBaru = [];
        foreach (static::legacyRelations() as $baru) {
            $relasiBaru[] = $baru;
            $relasiBaru[] = Str::snake($baru);
        }

        foreach ($data as $key => $value) {
            // Kolom baru & relasi baru ditulis ulang di bawah dengan nama lama.
            if (in_array($key, $kolomBaru, true) || in_array($key, $relasiBaru, true)) {
                continue;
            }
            $hasil[$key] = $value;
        }

        foreach (static::legacyAttributes() as $lama => $definisi) {
            if (is_array($definisi)) {
                // Query yang hanya memilih sebagian kolom (mis. `with('member:id,name')`)
                // tidak boleh memaksa memuat relasi — field-nya cukup dilewati,
                // persis seperti sebelum refactor.
                if (! array_key_exists($definisi['column'], $data)
                    && ! $this->relationLoaded($definisi['relation'])) {
                    continue;
                }

                $relasi = $this->getRelationValue($definisi['relation']);
                $hasil[$lama] = $relasi?->getAttribute($definisi['key']);

                continue;
            }

            if (array_key_exists($definisi, $data)) {
                $hasil[$lama] = $data[$definisi];
            }
        }

        foreach (static::legacyRelations() as $lama => $baru) {
            $key = array_key_exists($baru, $data) ? $baru : Str::snake($baru);

            if (array_key_exists($key, $data)) {
                $hasil[$lama] = $data[$key];
            }
        }

        return $hasil;
    }

    /**
     * Terjemahkan payload bernama lama (dari request) menjadi kolom database.
     *
     * Nilai kode master diubah menjadi id lewat pencarian ke tabel relasinya.
     * Kode yang tidak ditemukan menghasilkan null — validasi `exists` di
     * controller yang bertugas menolaknya lebih dulu.
     */
    public static function fromLegacy(array $data): array
    {
        foreach (static::legacyAttributes() as $lama => $definisi) {
            if (! array_key_exists($lama, $data)) {
                continue;
            }

            $nilai = $data[$lama];
            unset($data[$lama]);

            if (! is_array($definisi)) {
                $data[$definisi] = $nilai;

                continue;
            }

            $data[$definisi['column']] = $nilai === null || $nilai === ''
                ? null
                : static::cariIdRelasi($definisi, $nilai);
        }

        return $data;
    }

    /** Nama kolom database untuk sebuah nama lama (dipakai filter & pengurutan). */
    public static function kolomBaru(string $lama): string
    {
        $definisi = static::legacyAttributes()[$lama] ?? $lama;

        return is_array($definisi) ? $definisi['column'] : $definisi;
    }

    private static function cariIdRelasi(array $definisi, mixed $kode): ?int
    {
        $model = (new static)->{$definisi['relation']}()->getRelated();

        return $model->newQuery()->where($definisi['key'], $kode)->value('id');
    }
}
