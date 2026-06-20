# incomingCount

**Method:** GET
**Endpoint:** `/api/master/order-transfers/incoming-count`
**Controller:** `App\Http\Controllers\Transaction\OrderTransferController@incomingCount`
**Auth:** Bearer Token (wajib)

Jumlah permintaan pinjam-alih **masuk** yang masih `pending` untuk user yang login (sebagai
pemegang instrumen). Dipakai untuk badge notifikasi "Permintaan Pinjam".

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Jumlah permintaan pinjam masuk berhasil diambil.",
  "data": { "count": 2 }
}
```
