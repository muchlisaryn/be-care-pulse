# index

**Method:** GET
**Endpoint:** `/api/master/rooms`
**Controller:** `App\Http\Controllers\Master\RoomController@index`

## Request

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan `name` atau `code` (like) |
| page | integer | Tidak | Nomor halaman (default: 1) |
| per_page | integer | Tidak | Jumlah data per halaman (default: 20, maksimum: 500). Dipakai dropdown pilihan ruangan yang butuh daftar lengkap |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data ruangan berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "RUCR",
        "name": "Ruang CSSD",
        "created_by": "Admin",
        "updated_by": "Admin",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-05-21T09:00:00.000000Z",
        "updated_at": "2026-05-21T09:00:00.000000Z"
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
