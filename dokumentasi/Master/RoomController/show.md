# show

**Method:** GET
**Endpoint:** `/api/master/rooms/{id}`
**Controller:** `App\Http\Controllers\Master\RoomController@show`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID ruangan |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail ruangan berhasil diambil.",
  "data": {
    "id": 1,
    "name": "Ruang CSSD",
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
  "message": "No query results for model [App\\Models\\Room]."
}
```
