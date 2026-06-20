# store

**Method:** POST
**Endpoint:** `/api/master/bmhps`
**Controller:** `App\Http\Controllers\Master\BmhpController@store`
**Auth:** Bearer Token (wajib)

Tambah BMHP. `code` di-generate otomatis (`BMHP-NNN`).

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama BMHP |
| unit | string | Tidak | Satuan (default `pcs`) |
| stock_qty | integer | Tidak | Stok awal (default 0) |
| description | string | Tidak | Keterangan |

### Contoh
```json
{ "name": "Kasa Steril", "unit": "pcs", "stock_qty": 100 }
```

## Response

### Success (201)
```json
{
  "status": true,
  "message": "BMHP berhasil ditambahkan.",
  "data": { "id": 1, "code": "BMHP-001", "name": "Kasa Steril", "unit": "pcs", "stock_qty": 100 }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "name": ["The name field is required."] }
}
```
