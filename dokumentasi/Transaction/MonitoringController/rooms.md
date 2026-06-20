# rooms

**Method:** GET  
**Endpoint:** `/api/master/monitoring/rooms`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@rooms`  
**Auth:** Bearer Token (wajib)

Monitoring per ruangan: menampilkan instrumen yang **sedang dipinjam** di tiap
ruangan, yaitu order berstatus `dipinjam` dengan item yang belum dikembalikan
(`is_returned = false`).

Unit dikelompokkan **per (order, asal, nama paket, katalog instrumen)**. Jika
sebuah order meminjam beberapa unit dari katalog yang sama (asal & paket sama),
mereka digabung menjadi satu baris dengan `qty` sesuai jumlah unit; detail tiap
unit fisik tetap tersedia di array `units`. Order yang hanya mengambil satu item
menghasilkan `qty: 1`.

Setiap baris menyertakan `source` (`satuan`/`paket`) dan `package_name` (nama
paket bila berasal dari paket, selain itu `null`) agar frontend bisa
mengelompokkan tampilan **per paket**.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan `name` atau `code` ruangan (like) |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data monitoring ruangan berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 2,
        "code": "RMYS",
        "name": "Poli gigi",
        "borrowed_count": 2,
        "instrument_count": 1,
        "instruments": [
          {
            "order_code": "ORD-002",
            "borrowed_by": "Bagas",
            "order_date": "2026-06-10T00:00:00.000000Z",
            "return_plan_date": "2026-06-12T00:00:00.000000Z",
            "source": "satuan",
            "package_name": null,
            "instrument": { "id": 2, "code": "NRQU", "name": "tensi" },
            "qty": 2,
            "units": [
              {
                "instrument_stock_id": 9,
                "code": "NRQU-001",
                "status": "dipinjam",
                "condition": { "id": 1, "name": "Baik" }
              },
              {
                "instrument_stock_id": 10,
                "code": "NRQU-002",
                "status": "dipinjam",
                "condition": { "id": 1, "name": "Baik" }
              }
            ]
          }
        ]
      },
      {
        "id": 1,
        "code": "JWGL",
        "name": "poli umum",
        "borrowed_count": 0,
        "instrument_count": 0,
        "instruments": []
      }
    ],
    "per_page": 20,
    "total": 2,
    "last_page": 1
  }
}
```

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
}
```

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
