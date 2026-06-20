# index

**Method:** GET
**Endpoint:** `/api/master/order-transfers`
**Controller:** `App\Http\Controllers\Transaction\OrderTransferController@index`
**Auth:** Bearer Token (wajib)

Daftar permintaan pinjam-alih (handover) instrumen.

- `box=incoming` (default): permintaan **masuk** untuk user (sebagai pemegang yang meng-ACC).
- `box=outgoing`: permintaan yang **diajukan** user (sebagai peminjam baru) — untuk pantau status.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| box | string | Tidak | `incoming` (default) / `outgoing` |
| status | string | Tidak | `pending` / `accepted` / `rejected` / `canceled` |
| search | string | Tidak | Cari kode order/transaksi, nama peminjam, atau ruangan tujuan |
| page | int | Tidak | Halaman (paginate 20) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Daftar permintaan pinjam berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "from_order_id": 5,
        "holder_user_id": 3,
        "requested_by_user_id": 7,
        "to_room_id": 2,
        "borrowed_by": "Perawat A",
        "note": null,
        "status": "pending",
        "responded_at": null,
        "new_order_id": null,
        "from_order": { "id": 5, "code": "ORD-005", "code_transaction": "INV20260619001", "room": { "id": 1, "name": "IGD" } },
        "to_room": { "id": 2, "name": "OK 1" },
        "requested_by": { "id": 7, "name": "User OK" },
        "items": [
          { "id": 1, "instrument_stock_id": 30, "source": "paket", "package_name": "Set Minor", "instrument_stock": { "id": 30, "code": "KLL-002", "instrument": { "id": 4, "name": "Klem Lurus" } } }
        ]
      }
    ],
    "last_page": 1,
    "total": 1
  }
}
```
