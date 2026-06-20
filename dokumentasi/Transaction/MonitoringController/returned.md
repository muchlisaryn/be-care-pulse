# returned

**Method:** GET  
**Endpoint:** `/api/master/monitoring/returned`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@returned`  
**Auth:** Bearer Token (wajib)

Order yang sudah **dikembalikan** (status `dikembalikan`) — tetap dipajang di halaman
monitoring sebagai riwayat (tidak hilang setelah semua unit kembali). Berisi ringkasan
order; detail unit + kondisi keluar/masuk diambil lewat endpoint
`POST /api/master/orders/scan` saat baris dibuka (modal Riwayat Pengembalian).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter `code` order, `borrowed_by`, atau `name` ruangan (like) |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data order dikembalikan berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "code": "ORD-012",
        "status": "dikembalikan",
        "borrowed_by": "Muchlis Aryana",
        "room": { "id": 1, "name": "Annur 1" },
        "order_date": "2026-06-17T00:00:00.000000Z",
        "return_plan_date": "2026-06-18T00:00:00.000000Z",
        "returned_at": "2026-06-19T08:30:00.000000Z",
        "total_units": 5
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated. Silakan login terlebih dahulu."
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
