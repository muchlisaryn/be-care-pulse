# destroy

**Method:** DELETE  
**Endpoint:** `/api/master/orders/{order}`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@destroy`  
**Auth:** Bearer Token (wajib)

Soft delete order (mengisi `deleted_at` + `deleted_by`). Data tidak hilang permanen.

**Order yang sudah diproses tidak bisa dibatalkan/dihapus:** bila status order
`dipinjam` atau `dikembalikan` (unit fisik sudah dialokasikan), permintaan ditolak
dengan 422. Hanya order `diajukan`, `disetujui`, atau `dibatalkan` yang bisa dihapus.

**Nomor ORD & INV dilepas saat dihapus:** order yang dihapus tidak lagi menahan
nomor urutnya — `code` dan `code_transaction` diubah menjadi `VOID-{kode lama}-{id}`
(mis. `ORD-010` → `VOID-ORD-010-10`, `INV20260717002` → `VOID-INV20260717002-14`)
sehingga kedua nomor itu bisa dipakai lagi oleh order berikutnya. Kode lama tetap
tersimpan sebagai jejak, dan riwayat yang sudah merekam kode itu (`order_event`,
`instrument_stock_log`) tidak ikut berubah.

Khusus INV: karena satu rantai pinjam-alih sengaja berbagi `code_transaction` yang
sama, menghapus satu order dalam rantai **tidak** membebaskan nomor INV selama order
lain di rantai itu masih memakainya.

Bila order di-`restore`, kode aslinya dipulihkan. Kalau nomor ORD-nya sudah keburu
dipakai order lain, order yang di-restore mendapat nomor ORD baru; kalau nomor INV-nya
sudah dipakai, `code_transaction` dikosongkan dan dibangkitkan ulang saat order
diproses lagi.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Peminjaman berhasil dihapus."
}
```

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
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
