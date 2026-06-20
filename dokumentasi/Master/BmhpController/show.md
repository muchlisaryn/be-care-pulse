# show

**Method:** GET
**Endpoint:** `/api/master/bmhps/{bmhp}`
**Controller:** `App\Http\Controllers\Master\BmhpController@show`
**Auth:** Bearer Token (wajib)

Detail satu BMHP.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail BMHP berhasil diambil.",
  "data": { "id": 1, "code": "BMHP-001", "name": "Kasa Steril", "unit": "pcs", "stock_qty": 100, "description": null }
}
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
