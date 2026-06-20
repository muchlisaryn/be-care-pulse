# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/instruments/{id}`
**Controller:** `App\Http\Controllers\Master\InstrumentController@update`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID instrumen |

### Body (JSON)
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode instrumen, unik kecuali milik record ini (`unique:instruments,code` + ignore id) |
| name | string | Ya | Nama instrumen |

> `code` kini dapat diubah (teks bebas) selama tetap unik.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Instrumen berhasil diperbarui.",
  "data": {
    "id": 1,
    "code": "INS-001",
    "name": "Stetoskop Digital",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-05-21T09:00:00.000000Z",
    "updated_at": "2026-05-21T09:10:00.000000Z"
  }
}
```
