# InstrumentCatalogController — store

**Method:** POST
**Endpoint:** /api/master/instrument-catalogs
**Auth:** Bearer Token (wajib)

Membuat katalog instrumen beserta rincian (`items`). `code` diisi manual (teks bebas) dan **wajib unik**.
Tipe `single` wajib **tepat 1** rincian; tipe `paket` minimal 1 rincian.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode katalog, unik (`unique:instrument_catalogs,code`) |
| name | string | Ya | Nama katalog |
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
