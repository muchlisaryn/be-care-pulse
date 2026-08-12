# storage:release-stale-reservations

**Command:** `php artisan storage:release-stale-reservations [--dry-run]`
**Class:** `App\Console\Commands\ReleaseStaleStorageReservations`

Lepas reservasi gudang steril yang menempel pada order yang sudah tidak berjalan.

## Masalah yang diselesaikan

`instrument_storages.order_id` diisi saat unit dialokasikan untuk sebuah order
(`allocateFefo` / `reallocateDistribution`) dan seharusnya berakhir ketika unit
benar-benar keluar rak (baris jadi `status = keluar`). Bila ordernya batal, dihapus,
atau selesai sebelum itu, reservasinya tidak pernah dilepas: unit **hilang dari pool
steril** padahal fisiknya masih di rak — inilah stok yang "terlihat ada di gudang tapi
tidak bisa didistribusikan".

Sejak pembatalan & penghapusan order melepasnya sendiri
(`OrderController::releaseStorageReservations`), command ini dipakai untuk:
1. membersihkan sisa yang telanjur ada sebelum perbaikan itu, dan
2. jaring pengaman berkala bila ada jalur lain yang terlewat.

## Kriteria "order tidak berjalan"

Dibaca dari jejak, bukan kolom `status`:

- `canceled_at` terisi, **atau**
- `deleted_by` terisi, **atau**
- tidak lagi punya `order_item` dengan `is_returned = false`.

Baris gudang yang dilepas hanya yang **masih `tersimpan`**. Baris `keluar` adalah
riwayat pengeluaran — `order_id`-nya memang harus tetap menunjuk ordernya.

## Opsi

| Opsi | Keterangan |
|---|---|
| `--dry-run` | Tampilkan jumlah & rincian per order, tanpa mengubah data |

## Contoh

```
$ php artisan storage:release-stale-reservations --dry-run
12 baris gudang direservasi order yang sudah tidak berjalan (dry-run, tidak ada yang diubah).
+----------+----------------+
| Order    | Baris tertahan |
+----------+----------------+
| ORD-012  | 8              |
| ORD-031  | 4              |
+----------+----------------+

$ php artisan storage:release-stale-reservations
12 baris gudang dilepas kembali ke pool steril.
```

Baris yang dilepas ditandai `updated_by = system:release-stale-reservations`.
