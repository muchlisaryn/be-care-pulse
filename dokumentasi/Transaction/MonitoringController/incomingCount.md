# incomingCount

**Method:** GET  
**Endpoint:** `/api/master/monitoring/incoming-count`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@incomingCount`  
**Auth:** Bearer Token (wajib)

**Jumlah** order masuk saja (order berstatus `diajukan`) — sumber angka badge
notifikasi "Tracking Order" di sidebar frontend.

Dipisah dari [`incoming`](incoming.md) karena dipanggil sering: saat halaman dimuat,
saat tab browser kembali difokuskan, saat ada order baru (event Pusher
`order.submitted`), dan setelah order diterima / dibatalkan / dihapus. Endpoint ini
hanya menjalankan `count()` — tidak memuat 20 order beserta seluruh relasinya seperti
`incoming`.

**Penyaringnya wajib sama persis dengan `incoming`** (`status = diajukan`). Bila
`incoming` diubah, ubah juga di sini — kalau tidak, badge akan menampilkan angka yang
berbeda dari daftar yang benar-benar tampil di halaman Tracking Order.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

Tanpa query parameter.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Jumlah order masuk berhasil diambil.",
  "data": {
    "count": 3
  }
}
```

`count: 0` → tidak ada order masuk; frontend menyembunyikan badge notifikasi.

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
}
```
