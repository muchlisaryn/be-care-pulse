# cancel

**Method:** POST
**Endpoint:** `/api/master/order-transfers/{order_transfer}/cancel`
**Controller:** `App\Http\Controllers\Transaction\OrderTransferController@cancel`
**Auth:** Bearer Token (wajib) — hanya **pengaju** (`requested_by_user_id`)

Batalkan permintaan pinjam-alih selama belum di-ACC (status masih `pending`). Status menjadi
`canceled` + `responded_at` terisi. Tidak ada perpindahan unit.

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
  "message": "Permintaan pinjam dibatalkan.",
  "data": { "id": 1, "status": "canceled", "responded_at": "2026-06-19T08:10:00Z" }
}
```

### Error (403)
```json
{ "status": false, "message": "Hanya pengaju permintaan yang dapat membatalkan." }
```

### Error (422)
```json
{ "status": false, "message": "Permintaan ini sudah diproses dan tidak bisa dibatalkan." }
```
