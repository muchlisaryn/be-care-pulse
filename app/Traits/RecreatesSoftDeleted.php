<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * Membantu controller master data yang memakai *business key* (mis. `wilayah.kode`,
 * `pendidikan.nama`) pada tabel ber-soft-delete.
 *
 * Masalah yang dipecahkan: `HasAuditColumns` tidak pernah hard delete, sehingga
 * baris yang "dihapus" tetap menempati primary key / unique index-nya. Tanpa
 * penanganan khusus, user yang menghapus kode "01" lalu ingin membuatnya lagi
 * akan kena error unique — padahal kode itu tak terlihat di daftar mana pun.
 *
 * Pola pemakaian di `store()`:
 *
 *   1. Aturan `unique` diberi `->whereNull('deleted_by')` supaya baris yang sudah
 *      di-soft-delete tidak ikut memblokir validasi.
 *   2. Pembuatan record lewat `createOrRestore()` — bila ternyata ada baris
 *      soft-deleted dengan key yang sama, baris itu dihidupkan lagi dan ditimpa
 *      data baru, bukan di-insert ulang.
 */
trait RecreatesSoftDeleted
{
    /**
     * Buat record baru, atau hidupkan kembali record soft-deleted dengan business
     * key yang sama lalu timpa datanya.
     *
     * @param  class-string<Model>  $modelClass
     * @param  string  $keyColumn  kolom business key (primary key atau kolom unique)
     * @param  array<string, mixed>  $data
     */
    protected function createOrRestore(string $modelClass, string $keyColumn, array $data): Model
    {
        $key = $data[$keyColumn] ?? null;

        if ($key !== null) {
            $existing = $modelClass::withTrashed()
                ->where($keyColumn, $key)
                ->first();

            // Hanya baris yang benar-benar sudah dihapus yang boleh ditimpa;
            // baris aktif seharusnya sudah ditolak oleh aturan `unique`.
            if ($existing !== null && $existing->deleted_by !== null) {
                $existing->restore();
                $existing->fill($data)->save();

                return $existing;
            }
        }

        return $modelClass::create($data);
    }
}
