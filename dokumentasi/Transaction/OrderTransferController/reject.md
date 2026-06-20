# reject

**Method:** POST
**Endpoint:** `/api/master/order-transfers/{order_transfer}/reject`
**Controller:** `App\Http\Controllers\Transaction\OrderTransferController@reject`
**Auth:** Bearer Token (wajib) — hanya **pemegang saat ini** (`holder_user_id`)

Tolak permintaan pinjam-alih. Status menjadi `rejected` + `responded_at` terisi. Tidak ada
perpindahan unit.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order_transfer | int | ID permintaan transfer (status harus `pending`) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Permintaan pinjam ditolak.",
  "data": { "id": 1, "status": "rejected", "responded_at": "2026-06-19T08:05:00Z" }
}
```

### Error (403)
```json
{ "status": false, "message": "Hanya pemegang instrumen saat ini yang dapat menolak." }
```

### Error (422)
```json
{ "status": false, "message": "Permintaan ini sudah diproses." }
```
