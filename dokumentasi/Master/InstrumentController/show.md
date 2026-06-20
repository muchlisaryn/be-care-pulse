# show

**Method:** GET
**Endpoint:** `/api/master/instruments/{id}`
**Controller:** `App\Http\Controllers\Master\InstrumentController@show`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID instrumen |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail instrumen berhasil diambil.",
  "data": {
    "id": 1,
    "code": "INS-001",
    "name": "Stetoskop",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-05-21T09:00:00.000000Z",
    "updated_at": "2026-05-21T09:00:00.000000Z"
  }
}
```

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\Instrument]."
}
```
