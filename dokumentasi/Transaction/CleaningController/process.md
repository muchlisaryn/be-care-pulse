# CleaningController@process

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/process
**Auth:** Bearer Token (wajib)

Memproses order masuk: memindahkan order dari status `diajukan` ke tahap Cleaning
(`pencucian`). Mencatat `processed_at` & `processed_by`, membuat catatan pencucian
kosong (status `dalam_proses`), dan mencatat event timeline `diproses`. Tidak ada
alokasi unit fisik.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (harus berstatus `diajukan`) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Order berhasil diproses & masuk tahap Cleaning.",
  "data": {
    "id": 12,
    "code": "ORD-012",
    "status": "pencucian",
    "processed_at": "2026-06-25T08:00:00.000000Z",
    "processed_by": "Admin",
    "washing": { "status": "dalam_proses", "machine_no": null, "...": null }
  }
}
```

#### Error (422)
```json
{ "status": false, "message": "Order ini sudah diproses dan tidak bisa diproses lagi." }
```

#### Error (500)
```json
{ "status": false, "message": "pesan error asli", "code": 0, "file": "...", "line": 0 }
```
