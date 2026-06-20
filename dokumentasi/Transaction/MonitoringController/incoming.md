# incoming

**Method:** GET  
**Endpoint:** `/api/master/monitoring/incoming`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@incoming`  
**Auth:** Bearer Token (wajib)

Order masuk dari menu **Order Instrumen**: order berstatus `diajukan`
(belum dipinjamkan), **lintas user** — untuk dipantau CSSD di halaman
monitoring. Setiap order menyertakan ringkasan baris permintaan (`requestItems`):
total unit diminta (`requested_qty`), jumlah baris/jenis (`request_lines`), dan
daftar `items` (nama instrumen/paket + jumlah). Untuk item bertipe `paket`, ikut
disertakan `contents` — rincian instrumen di dalam **satu** paket (komposisi
katalog: nama, kode, dan jumlah per paket). Untuk item `satuan`, `contents` kosong.

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
  "message": "Data order masuk berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 7,
        "code": "ORD-001",
        "status": "diajukan",
        "borrowed_by": "Muchlis Aryana",
        "room": { "id": 1, "name": "Annur 1" },
        "order_date": "2026-06-17T00:00:00.000000Z",
        "return_plan_date": "2026-06-18T00:00:00.000000Z",
        "note": null,
        "requested_qty": 2,
        "request_lines": 2,
        "items": [
          {
            "type": "paket",
            "name": "SET PARTUS",
            "quantity": 1,
            "contents": [
              { "instrument": "Gunting Episiotomi", "code": "GNT", "quantity": 2 },
              { "instrument": "Klem Arteri", "code": "KLM", "quantity": 4 }
            ]
          },
          { "type": "satuan", "name": "Gunting", "quantity": 1, "contents": [] }
        ]
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
