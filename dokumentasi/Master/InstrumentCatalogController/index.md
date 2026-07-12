# InstrumentCatalogController — index

**Method:** GET
**Endpoint:** /api/master/instrument-catalogs
**Auth:** Bearer Token (wajib)

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `name` atau `code` |
| type | string | Tidak | Filter tipe: `single` / `paket` |
| page | int | Tidak | Halaman pagination (20 per halaman) |

> Setiap item juga menyertakan `image` (path relatif, `null` bila belum ada) dan
> `image_url` (URL publik gambar set, `null` bila belum ada). Kelola lewat
> `uploadImage` / `deleteImage`.

> Setiap item menyertakan `items_count` (jumlah jenis instrumen dalam katalog),
> `available_sets` — berapa set paket yang masih bisa dipenuhi dari stok `tersedia`
> (= minimum dari `floor(stok_tersedia / qty_per_set)` atas seluruh isinya; `0` jika ada item yang habis),
> dan `available_sterile_sets` — sama, tetapi dihitung dari stok STERIL yang memang
> **disimpan sebagai paket ini** (unit di gudang steril `tersimpan`, belum kedaluwarsa,
> `instrument_storages.source = paket` **dan** `package_name` = nama katalog ini).
> Unit satuan tidak bisa dipakai untuk memenuhi paket, karena bentuk barang (satuan /
> paket) ditentukan saat produksi. Dipakai halaman order karena order hanya boleh atas
> barang yang sudah steril.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data katalog instrumen berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "TJEO",
        "name": "Set Bedah Minor",
        "type": "paket",
        "description": null,
        "items_count": 2,
        "available_sets": 3,
        "available_sterile_sets": 1
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```
