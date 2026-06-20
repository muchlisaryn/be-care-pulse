# store

**Method:** POST
**Endpoint:** `/api/master/order-transfers`
**Controller:** `App\Http\Controllers\Transaction\OrderTransferController@store`
**Auth:** Bearer Token (wajib)

Buat permintaan pinjam-alih: peminjam baru meminta sebagian (paket / unit satuan) dari order yang
sedang dipinjam pihak lain. Request dikirim ke **pemegang saat ini** (`from_order.user_id`) untuk
di-ACC, dan disiarkan real-time lewat event `OrderTransferRequested` (channel `transfers`).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| from_order_id | int | Ya | Order sumber (harus berstatus `dipinjam`) |
| to_room_id | int | Ya | Ruangan tujuan peminjam baru |
| borrowed_by | string | Tidak | Nama peminjam baru |
| note | string | Tidak | Catatan untuk pemegang saat ini |
| instrument_stock_ids | int[] | Ya | Unit yang diminta (harus unit aktif milik order sumber) |

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Permintaan pinjam berhasil dikirim ke peminjam saat ini.",
  "data": { "id": 1, "status": "pending", "...": "..." }
}
```

### Error (422)
```json
{ "status": false, "message": "Sebagian unit tidak valid / sudah dikembalikan / bukan milik order ini." }
```

Pesan error lain yang mungkin:
- `Order sumber sedang tidak dipinjam, tidak bisa diminta.`
- `Instrumen sudah berada di ruangan tujuan tersebut.`
