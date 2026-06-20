# destroy

**Method:** DELETE  
**Endpoint:** `/api/master/orders/{order}`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@destroy`  
**Auth:** Bearer Token (wajib)

Soft delete order (mengisi `deleted_at` + `deleted_by`). Data tidak hilang permanen.

**Order yang sudah diproses tidak bisa dibatalkan/dihapus:** bila status order
`dipinjam` atau `dikembalikan` (unit fisik sudah dialokasikan), permintaan ditolak
dengan 422. Hanya order `diajukan`, `disetujui`, atau `dibatalkan` yang bisa dihapus.

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
