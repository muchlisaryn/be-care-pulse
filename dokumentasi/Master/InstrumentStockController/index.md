# index

**Method:** GET
**Endpoint:** `/api/master/instrument-stocks`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@index`

## Request

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| instrument_id | integer | Tidak | Filter hanya unit milik instrumen tertentu |
| search | string | Tidak | Filter berdasarkan `code` atau `name` instrumen (like) |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data stok instrumen berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "instrument_id": 1,
        "code": "INSK-001",
        "condition_id": 1,
        "status": "tersedia",
        "created_by": "Admin",
        "updated_by": "Admin",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-05-21T09:00:00.000000Z",
        "updated_at": "2026-05-21T09:00:00.000000Z",
        "instrument": { "id": 1, "code": "INS-001", "name": "Stetoskop" },
        "condition": { "id": 1, "name": "Baik" }
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
