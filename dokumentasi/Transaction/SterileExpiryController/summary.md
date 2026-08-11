# summary

**Method:** GET  
**Endpoint:** `/api/master/sterile-expiry/summary`  
**Controller:** `App\Http\Controllers\Transaction\SterileExpiryController@summary`  
**Auth:** Bearer Token (wajib)

Angka kartu statistik halaman **Alat Kedaluwarsa Steril** (`/cssd/kedaluwarsa`). Dihitung di server
agar tetap benar walau daftarnya dipecah per halaman — jangan menghitung ulang dari baris yang
kebetulan sedang tampil di klien.

Basis baris & aturan hitungnya sama persis dengan [`index`](index.md): baris gudang ber-`order_id`
NULL yang `expiry_date <= hari ini + days`, dengan **set dihitung 1** dan **satuan dihitung 1**
(set tidak dihitung per instrumen).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| days | integer | Tidak | Ambang hari ke depan (default: 7). Harus sama dengan yang dikirim ke `index` |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Ringkasan alat kedaluwarsa steril berhasil diambil.",
  "data": {
    "batches": 4,
    "items": 6,
    "expired": 6,
    "alert": 0
  }
}
```

| Field | Keterangan |
|---|---|
| `batches` | jumlah batch steril pada daftar (= `total` di `index`) |
| `items` | jumlah unit dalam ambang hari (set = 1, satuan = 1) |
| `expired` | unit yang tanggal kedaluwarsanya sudah lewat |
| `alert` | unit yang belum lewat tapi masuk ambang `days` |

`items` = `expired` + `alert`.

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
