# index

**Method:** GET
**Endpoint:** `/api/master/distributions`
**Controller:** `App\Http\Controllers\Transaction\DistributionController@index`
**Auth:** Bearer Token (wajib)

Daftar distribusi BMHP (serah-terima bahan medis habis pakai CSSD → unit/ruangan).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter `code`, nama ruangan, atau nama penerima |
| status | string | Tidak | `terdistribusi` / `dibatalkan` |
| page | integer | Tidak | Nomor halaman |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data distribusi BMHP berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "DST-001",
        "room_id": 1,
        "sender": "tri.aji",
        "receiver": "AMBAR MELANI",
        "distributed_at": "2026-06-11T14:24:00.000000Z",
        "status": "terdistribusi",
        "note": null,
        "items_count": 2,
        "room": { "id": 1, "code": "RWIN", "name": "RAWAT INAP" }
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
