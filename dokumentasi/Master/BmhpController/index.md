# index

**Method:** GET
**Endpoint:** `/api/master/bmhps`
**Controller:** `App\Http\Controllers\Master\BmhpController@index`
**Auth:** Bearer Token (wajib)

Daftar BMHP (Bahan Medis Habis Pakai / consumables).

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter `name` atau `code` |
| page | integer | Tidak | Nomor halaman |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data BMHP berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      { "id": 1, "code": "BMHP-001", "name": "Kasa Steril", "unit": "pcs", "stock_qty": 100, "description": null }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
