# InstrumentCatalogController — update

**Method:** PUT/PATCH
**Endpoint:** /api/master/instrument-catalogs/{id}
**Auth:** Bearer Token (wajib)

Memperbarui katalog. Rincian (`items`) di-**replace penuh**: rincian lama dihapus lalu dibuat ulang dari payload. Aturan validasi sama dengan `store`.

### Body Parameters
Sama persis dengan **store** (`code`, `name`, `type`, `description`, `items[]`). `code` **dan** `name`
wajib unik di antara katalog yang masih aktif, kecuali milik record ini (ignore id). Lihat `store.md`
untuk alasan `name` harus unik (stok steril paket dicocokkan berdasarkan nama persis).

> **Perhatian saat mengganti `name`:** stok steril paket yang sudah tersimpan di gudang memakai nama
> LAMA pada `instrument_storages.package_name`. Setelah katalog di-rename, stok lama tersebut tidak
> lagi terbaca sebagai stok paket ini (tampil 0 set) sampai diproduksi ulang dengan nama baru.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Katalog instrumen berhasil diperbarui.",
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

#### Error (422) — kode / nama sudah dipakai katalog aktif lain
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "name": ["Nama katalog ini sudah dipakai katalog lain yang masih aktif. Nama paket harus unik karena stok steril dicocokkan berdasarkan nama persis."]
  }
}
```
