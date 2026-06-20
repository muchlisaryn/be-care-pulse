# store

**Method:** POST
**Endpoint:** `/api/master/instruments`
**Controller:** `App\Http\Controllers\Master\InstrumentController@store`

## Request

### Body (JSON)
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode instrumen, diisi manual (teks bebas), unik (`unique:instruments,code`) |
| name | string | Ya | Nama instrumen |

> `code` kini diisi manual oleh client dan harus unik. (Sebelumnya di-generate otomatis.)

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Instrumen berhasil ditambahkan.",
  "data": {
    "id": 1,
    "code": "ABCD",
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

### Error (422)
```json
{
  "message": "The code has already been taken.",
  "errors": {
    "code": ["The code has already been taken."]
  }
}
```
