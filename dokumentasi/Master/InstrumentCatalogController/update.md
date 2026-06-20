# InstrumentCatalogController — update

**Method:** PUT/PATCH
**Endpoint:** /api/master/instrument-catalogs/{id}
**Auth:** Bearer Token (wajib)

Memperbarui katalog. Rincian (`items`) di-**replace penuh**: rincian lama dihapus lalu dibuat ulang dari payload. Aturan validasi sama dengan `store`.

### Body Parameters
Sama persis dengan **store** (`code`, `name`, `type`, `description`, `items[]`). `code` wajib unik kecuali milik record ini (ignore id).

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
