# InstrumentCatalogController — store

**Method:** POST
**Endpoint:** /api/master/instrument-catalogs
**Auth:** Bearer Token (wajib)

Membuat katalog instrumen beserta rincian (`items`). `code` diisi manual (teks bebas) dan **wajib unik**.
Tipe `single` wajib **tepat 1** rincian; tipe `paket` minimal 1 rincian.

`name` juga **wajib unik** di antara katalog yang masih aktif (`deleted_by IS NULL`). Stok steril paket
di gudang dicocokkan lewat `instrument_storages.package_name` — pencocokan **nama persis**, bukan id —
sehingga dua katalog aktif bernama sama akan membaca stok yang sama dan membuat paket tanpa barang
tetap bisa di-order. Nama katalog yang sudah di-soft-delete boleh dipakai ulang.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode katalog, unik antar katalog aktif (`unique:instrument_catalogs,code` + `deleted_by IS NULL`) |
| name | string | Ya | Nama katalog, **unik antar katalog aktif** (`unique:instrument_catalogs,name` + `deleted_by IS NULL`) |
| type | string | Ya | `single` / `paket` |
| description | string | Tidak | Deskripsi |
| items | array | Ya | Minimal 1 rincian |
| items[].instrument_id | int | Ya | `exists:instruments,id` |
| items[].quantity | int | Ya | Min 1 |
| items[].standard_condition_id | int | Tidak | Kondisi standar, `exists:conditions,id` |
| items[].note | string | Tidak | Catatan rincian |

### Response — Success (201)
```json
{
  "status": true,
  "message": "Katalog instrumen berhasil ditambahkan.",
  "data": { "id": 1, "code": "TJEO", "name": "Set Bedah Minor", "type": "paket", "items": [ ... ] }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Tipe single hanya boleh memiliki tepat 1 rincian instrumen.",
  "errors": { "items": ["Tipe single hanya boleh memiliki 1 rincian instrumen."] }
}
```

#### Error (422) — kode / nama sudah dipakai
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "name": ["Nama katalog ini sudah dipakai katalog lain yang masih aktif. Nama paket harus unik karena stok steril dicocokkan berdasarkan nama persis."],
    "code": ["Kode katalog ini sudah dipakai katalog lain yang masih aktif."]
  }
}
```
