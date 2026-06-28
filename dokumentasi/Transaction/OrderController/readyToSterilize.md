# OrderController@readyToSterilize

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** GET
**Endpoint:** /api/master/orders/ready-to-sterilize
**Auth:** Bearer Token (wajib)

Daftar order yang sudah selesai packaging (status `selesai`) dan **siap disterilkan**
(Tahap 4 — Sterilization). Mengembalikan ringkasan unit fisik tiap order yang akan
masuk batch sterilisasi.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada kode order, no. batch (`code_transaction`), peminjam, atau nama ruangan |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data order siap sterilisasi berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "code": "ORD-012",
        "code_transaction": "TRX-000045",
        "status": "selesai",
        "borrowed_by": "OK 1",
        "room": { "id": 3, "name": "OK 1" },
        "order_date": "2026-06-28",
        "processed_at": "2026-06-28T08:30:00.000000Z",
        "unit_count": 2,
        "units": [
          { "id": 101, "code": "KLL-001", "instrument": "Klem", "source": "satuan", "package_name": null }
        ]
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
