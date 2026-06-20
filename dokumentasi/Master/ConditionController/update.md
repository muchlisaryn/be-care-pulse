# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/conditions/{id}`
**Controller:** `App\Http\Controllers\Master\ConditionController@update`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID kondisi |

### Body (JSON)
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama kondisi, harus unik (kecuali milik diri sendiri) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Kondisi berhasil diperbarui.",
  "data": {
    "id": 1,
    "name": "Rusak",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-05-21T09:00:00.000000Z",
    "updated_at": "2026-05-21T09:10:00.000000Z"
  }
}
```

### Error (422)
```json
{
  "message": "The name has already been taken.",
  "errors": {
    "name": ["The name has already been taken."]
  }
}
```
