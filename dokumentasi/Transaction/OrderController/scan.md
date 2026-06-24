# scan

**Method:** POST  
**Endpoint:** `/api/master/orders/scan`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@scan`  
**Auth:** Bearer Token (wajib)

Lookup untuk halaman Scan & Tracking. Pelacakan berbasis **order** — response berisi header
order + seluruh unit di dalamnya beserta status masing-masing.

`code` menerima tiga bentuk:
- **Kode order** (`ORD-NNN`) → tampilkan order tersebut.
- **Kode transaksi** (`INV...`, barcode order) → tampilkan order tersebut.
- **Kode unit alat** (mis. `KLL-002`) → cari **order terakhir** yang memuat unit itu, lalu
  tampilkan ordernya. QR per-unit yang sudah tercetak tetap bisa dipakai.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode order (`ORD-001`) atau kode unit alat (`KLL-002`) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail order berhasil diambil.",
  "data": {
    "id": 1,
    "code": "ORD-001",
    "room_id": 1,
    "user_id": 1,
    "borrowed_by": "Bagas",
    "order_date": "2026-06-08T00:00:00.000000Z",
    "order_time": "14:30:00",
    "return_plan_date": "2026-06-10",
    "return_actual_date": null,
    "returned_by": null,
    "status": "dipinjam",
    "note": "Untuk operasi minor",
    "room": { "id": 1, "code": "JWGL", "name": "poli umum" },
    "user": { "id": 1, "name": "Administrator" },
    "items": [
      {
        "id": 1,
        "order_id": 1,
        "instrument_stock_id": 1,
        "condition_out_id": 1,
        "condition_in_id": null,
        "is_returned": false,
        "instrument_stock": {
          "id": 1, "code": "ZHVQ-001", "status": "dipinjam",
          "instrument": { "id": 1, "code": "ZHVQ", "name": "stetoskop" }
        },
        "condition_out": { "id": 1, "name": "Baik" },
        "condition_in": null
      }
    ],
    "timeline": [
      { "id": 1, "type": "dibuat", "room": "IGD", "actor": "Admin", "borrowed_by": "Perawat A", "note": "Order peminjaman diajukan", "created_at": "2026-06-19T07:00:00Z" },
      { "id": 2, "type": "diterima", "room": "IGD", "actor": "CSSD", "borrowed_by": "Perawat A", "note": "Order diterima & unit dipinjamkan CSSD", "created_at": "2026-06-19T07:30:00Z" },
      { "id": 3, "type": "dipindah", "room": "OK 1", "actor": "Perawat A", "borrowed_by": "Perawat B", "note": "Dipinjam dari ruangan IGD ke OK 1 oleh Perawat B", "created_at": "2026-06-19T09:00:00Z" },
      { "id": 4, "type": "dikembalikan", "room": "OK 1", "actor": "Perawat B", "borrowed_by": "Perawat B", "note": "Seluruh unit dikembalikan", "created_at": "2026-06-19T12:00:00Z" }
    ]
  }
}
```

> **`timeline`** — riwayat tracking lintas seluruh order yang berbagi `code_transaction` (rantai
> handover antar ruangan), diurutkan kronologis. Tipe event: `dibuat` / `diterima` / `dipinjam` /
> `dipindah` / `dikembalikan`.

### Error (404)
```json
{
  "status": false,
  "message": "Order atau unit dengan kode \"ORD-999\" tidak ditemukan."
}
```

Bila kode dikenali sebagai unit alat tapi unit itu belum pernah masuk order:
```json
{
  "status": false,
  "message": "Unit \"KLL-002\" belum pernah masuk order manapun."
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "code": ["The code field is required."] }
}
```
