# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/instrument-stocks/{id}`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@update`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID stok instrumen |

### Body (JSON)
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| instrument_id | integer | Ya | ID instrumen, harus ada di tabel `instruments` |
| condition_id | integer | Tidak | ID kondisi, harus ada di tabel `conditions` |
| status | string | Tidak | Status unit: `tersedia`, `dipinjam`, `sterilisasi`, `dikembalikan` |

> `code` tidak dapat diubah setelah dibuat.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Stok instrumen berhasil diperbarui.",
  "data": {
    "id": 1,
    "instrument_id": 1,
    "code": "INSK-001",
    "condition_id": 2,
    "status": "dipinjam",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-05-21T09:00:00.000000Z",
    "updated_at": "2026-05-21T09:10:00.000000Z",
    "instrument": { "id": 1, "code": "INS-001", "name": "Stetoskop" },
    "condition": { "id": 2, "name": "Rusak" }
  }
}
```
