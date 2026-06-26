# CleaningController@index

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** GET
**Endpoint:** /api/master/cleaning
**Auth:** Bearer Token (wajib)

Daftar order yang sedang berada di tahap Cleaning & Pengemasan
(status `pencucian` atau `pengemasan`), beserta catatan pencucian & ringkasan
permintaan. Mendukung `search` + pagination (20/halaman).

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada code / borrowed_by / nama ruangan |
| page | integer | Tidak | Halaman |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data order tahap cleaning berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "code": "ORD-012",
        "status": "pencucian",
        "borrowed_by": "Ruang OK",
        "room": { "id": 3, "name": "OK 1" },
        "processed_at": "2026-06-25T08:00:00.000000Z",
        "requested_qty": 5,
        "request_lines": 2,
        "items": [{ "type": "satuan", "name": "Gunting", "quantity": 2 }],
        "washing": { "status": "dalam_proses", "machine_no": "M-01", "temperature": "60", "...": null }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
