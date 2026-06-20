# show

**Method:** GET
**Endpoint:** `/api/master/distributions/{distribution}`
**Controller:** `App\Http\Controllers\Transaction\DistributionController@show`
**Auth:** Bearer Token (wajib)

Detail satu distribusi beserta item BMHP dan penerima/pengirim.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| distribution | integer | ID distribusi |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail distribusi berhasil diambil.",
  "data": {
    "id": 1,
    "code": "DST-001",
    "status": "terdistribusi",
    "distributed_at": "2026-06-11T14:24:00.000000Z",
    "note": null,
    "room": { "id": 1, "code": "RWIN", "name": "RAWAT INAP" },
    "sender": { "id": 1, "name": "tri.aji" },
    "receiver": { "id": 2, "name": "AMBAR MELANI" },
    "items": [
      {
        "id": 1, "bmhp_id": 1, "quantity": 10, "note": "Kasa untuk ranap",
        "bmhp": { "id": 1, "code": "BMHP-001", "name": "Kasa Steril", "unit": "pcs" }
      }
    ]
  }
}
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
