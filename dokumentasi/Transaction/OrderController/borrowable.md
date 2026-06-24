# borrowable

**Method:** GET
**Endpoint:** `/api/master/orders/borrowable`
**Controller:** `App\Http\Controllers\Transaction\OrderController@borrowable`
**Auth:** Bearer Token (wajib)

Daftar order yang sedang **dipinjam** (oleh ruangan mana pun) beserta unit yang belum dikembalikan.
Dipakai halaman **Pinjam Instrumen** sebagai sumber unit yang bisa diminta pinjam-alih (handover)
antar ruangan tanpa order ulang ke CSSD. Pembeda peminjam adalah **ruangan**, bukan akun user —
sistem dapat berjalan dengan satu akun.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari berdasarkan kode order, nama peminjam, atau nama ruangan |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Daftar instrumen yang bisa dipinjam berhasil diambil.",
  "data": [
    {
      "id": 5,
      "code": "ORD-005",
      "code_transaction": "INV20260619001",
      "borrowed_by": "Ruang OK 1",
      "room": { "id": 2, "name": "OK 1" },
      "order_date": "2026-06-19T00:00:00.000000Z",
      "order_time": "14:30:00",
      "return_plan_date": "2026-06-21",
      "units": [
        {
          "order_item_id": 12,
          "instrument_stock_id": 30,
          "code": "KLL-002",
          "instrument_name": "Klem Lurus",
          "source": "paket",
          "package_name": "Set Minor"
        }
      ]
    }
  ]
}
```

> Tidak dipaginasi — hanya order berstatus `dipinjam` milik user lain yang masih memiliki unit aktif.
