# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/rooms/{id}`
**Controller:** `App\Http\Controllers\Master\RoomController@update`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID ruangan |

### Body (JSON)
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama ruangan, harus unik (kecuali milik diri sendiri) |
| layanan | string | Tidak | Layanan ruangan: `igd` \| `rawat_jalan` \| `rawat_inap` |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Ruangan berhasil diperbarui.",
  "data": {
    "id": 1,
    "name": "Ruang Sterilisasi",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-05-21T09:00:00.000000Z",
    "updated_at": "2026-05-21T09:10:00.000000Z"
  }
}
```
