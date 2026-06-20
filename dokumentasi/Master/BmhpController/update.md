# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/bmhps/{bmhp}`
**Controller:** `App\Http\Controllers\Master\BmhpController@update`
**Auth:** Bearer Token (wajib)

Perbarui BMHP.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Tidak | Nama BMHP |
| unit | string | Tidak | Satuan |
| stock_qty | integer | Tidak | Stok |
| description | string | Tidak | Keterangan |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "BMHP berhasil diperbarui.",
  "data": { "id": 1, "code": "BMHP-001", "name": "Kasa Steril", "unit": "box", "stock_qty": 80 }
}
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
