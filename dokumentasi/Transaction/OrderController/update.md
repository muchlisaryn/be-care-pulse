# update

**Method:** PUT / PATCH  
**Endpoint:** `/api/master/orders/{order}`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@update`  
**Auth:** Bearer Token (wajib)

Dipakai untuk **mengubah status order** (setujui / pinjamkan / batalkan / kembalikan) dan
**memproses pengembalian per-unit**. Keduanya bisa dikirim sekaligus dan dijalankan dalam satu transaksi DB.

### Alur status & efek ke unit instrumen
| Status baru | Efek pada `instrument_stocks.status` |
|-------------|--------------------------------------|
| `disetujui` | Tidak mengubah status unit |
| `dipinjam` | Semua unit terkait → `dipinjam` |
| `dikembalikan` | Semua unit terkait → `tersedia`, semua item `is_returned=true`, `return_actual_date` diisi hari ini bila kosong, `returned_by` diisi bila dikirim |
| `dibatalkan` | Semua unit terkait → `tersedia` (rollback); `canceled_at` + `canceled_by` diisi. Order **tidak** dihapus — tetap tersimpan sebagai riwayat |

> **Batal vs Hapus.** Mengubah `status` ke `dibatalkan` (lewat endpoint ini) hanya
> menandai order batal: mengisi `canceled_at`/`canceled_by`, data tetap ada.
> **Hapus** (`DELETE /api/master/orders/{order}`) adalah *soft delete*: mengisi
> `deleted_at`/`deleted_by` dan menyembunyikan order dari daftar. Keduanya
> **tidak pernah** menghapus permanen (no hard delete).

Alur normal: `diajukan` → `disetujui` → `dipinjam` → `dikembalikan` (atau `dibatalkan`).

### Pengembalian per-item
Kirim array `items` berisi `id` order_item. Bila item ditandai `is_returned=true`, unit
terkait otomatis kembali `tersedia`. Bila **seluruh** item sudah `is_returned`, order otomatis
menjadi `dikembalikan` dan `return_actual_date` diisi.

### Pengembali (`returned_by`)
Nama orang yang mengembalikan instrumen (teks bebas, mirip `borrowed_by` saat order dibuat).
Kirim bersama aksi pengembalian — baik saat `status=dikembalikan` maupun saat menandai
`items[].is_returned=true`. Tersimpan di kolom `returned_by` order.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| status | string | Tidak | Salah satu: `diajukan`, `disetujui`, `dipinjam`, `dikembalikan`, `dibatalkan` |
| return_plan_date | date | Tidak | Ubah rencana tanggal kembali |
| returned_by | string | Tidak | Nama orang yang mengembalikan (diisi saat pengembalian) |
| note | string | Tidak | Ubah catatan |
| items | array | Tidak | Daftar item yang diproses pengembaliannya |
| items[].id | integer | Ya (jika `items` ada) | ID order_item milik order ini |
| items[].is_returned | boolean | Tidak | Tandai unit sudah dikembalikan |
| items[].condition_in_id | integer | Tidak | Kondisi unit saat dikembalikan |

### Contoh — Pinjamkan order
```json
{ "status": "dipinjam" }
```

### Contoh — Kembalikan sebagian unit
```json
{
  "returned_by": "Budi",
  "items": [
    { "id": 1, "is_returned": true, "condition_in_id": 1 }
  ]
}
```

### Contoh — Kembalikan semua
```json
{ "status": "dikembalikan", "returned_by": "Budi" }
```

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Peminjaman berhasil diperbarui.",
  "data": {
    "id": 1,
    "code": "ORD-001",
    "status": "dipinjam",
    "return_actual_date": null,
    "returned_by": null,
    "room": { "id": 1, "code": "JWGL", "name": "poli umum" },
    "user": { "id": 1, "name": "Administrator", "username": "administrator" },
    "items": [
      {
        "id": 1,
        "instrument_stock_id": 1,
        "condition_out_id": 1,
        "condition_in_id": null,
        "is_returned": false,
        "instrument_stock": { "id": 1, "code": "ZHVQ-001", "status": "dipinjam" }
      }
    ]
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "status": ["The selected status is invalid."]
  }
}
```

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
