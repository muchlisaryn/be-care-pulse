# borrowable

**Method:** GET
**Endpoint:** `/api/master/orders/borrowable`
**Controller:** `App\Http\Controllers\Transaction\OrderController@borrowable`
**Auth:** Bearer Token (wajib)

Daftar order yang sedang **dipinjam** (oleh ruangan mana pun) beserta unit yang belum dikembalikan.
Dipakai halaman **Pinjam Instrumen** sebagai sumber unit yang bisa diminta pinjam-alih (handover)
antar ruangan tanpa order ulang ke CSSD.

### Order milik sendiri dikecualikan
Tidak bisa meminjam dari diri sendiri. Sebuah order **disembunyikan** bila memenuhi
**salah satu** syarat berikut — bukan hanya kombinasi keduanya:

1. `order.user_id` = user yang sedang login (ordernya saya yang buat), termasuk bila
   `borrowed_by` diisi nama orang lain.
2. `order.borrowed_by` = nama user yang sedang login (saya peminjam yang tercatat),
   termasuk bila ordernya dibuatkan orang lain.

Order tanpa `borrowed_by` yang dibuat orang lain tetap muncul.

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
      "order_date": "2026-06-19",
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
