# show

**Method:** GET
**Endpoint:** `/api/master/instrument-stocks/{id}`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@show`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID stok instrumen |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail stok instrumen berhasil diambil.",
  "data": {
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
}
```

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\InstrumentStock]."
}
```
