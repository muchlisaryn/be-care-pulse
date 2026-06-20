# index

**Method:** GET  
**Endpoint:** `/api/master/orders`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@index`  
**Auth:** Bearer Token (wajib)

> Daftar yang dikembalikan **hanya order milik akun yang login** (difilter `user_id = id user login`). Tiap akun hanya melihat order yang dibuatnya sendiri.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan `code` order atau `name` ruangan (like) |
| status | string | Tidak | Filter berdasarkan status order (`diajukan`, `disetujui`, `dipinjam`, `dikembalikan`, `dibatalkan`) |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data peminjaman berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "ORD-001",
        "room_id": 1,
        "user_id": 1,
        "order_date": "2026-06-08",
        "return_plan_date": "2026-06-10",
        "return_actual_date": null,
        "status": "diajukan",
        "note": "Untuk operasi minor",
        "items_count": 2,
        "paket_items_count": 1,
        "satuan_items_count": 1,
        "created_by": "Administrator",
        "updated_by": "Administrator",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-06-08T08:00:00.000000Z",
        "updated_at": "2026-06-08T08:00:00.000000Z",
        "room": { "id": 1, "code": "JWGL", "name": "poli umum" },
        "user": { "id": 1, "name": "Administrator", "username": "administrator" }
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
