# accept

**Method:** POST
**Endpoint:** `/api/master/order-transfers/{order_transfer}/accept`
**Controller:** `App\Http\Controllers\Transaction\OrderTransferController@accept`
**Auth:** Bearer Token (wajib) — hanya **pemegang saat ini** (`holder_user_id`)

Setujui permintaan pinjam-alih. Membuat **order baru** untuk peminjam baru yang berbagi
`code_transaction` (invoice) sama dengan order sumber, lalu memindahkan unit (`order_item`) terpilih
ke order baru. Unit tetap berstatus `dipinjam` (tidak menyentuh stok) — hanya berpindah
pemegang/ruangan. Dicatat sebagai event timeline `dipindah` pada `code_transaction`.

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
  "message": "Permintaan disetujui. Instrumen telah berpindah ke peminjam baru.",
  "data": { "id": 1, "status": "accepted", "new_order_id": 9, "responded_at": "2026-06-19T08:00:00Z" }
}
```

### Error (403)
```json
{ "status": false, "message": "Hanya pemegang instrumen saat ini yang dapat menyetujui." }
```

### Error (422)
```json
{ "status": false, "message": "Permintaan ini sudah diproses." }
```
Atau: `Sebagian unit sudah tidak tersedia pada order sumber.`
