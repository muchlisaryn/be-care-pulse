# counts

**Method:** GET  
**Endpoint:** `/api/master/monitoring/counts`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@counts`  
**Auth:** Bearer Token (wajib)

Angka badge pada tab halaman Tracking Order (`/cssd/tracking-order`).

Murni `count()` di database — **tidak** memuat satu pun baris order beserta relasinya.
Sebelumnya frontend mendapatkan angka ini dengan menarik SELURUH halaman daftar lalu
menghitung panjang arraynya; untuk gudang dengan ribuan order itu berat dan lambat,
padahal yang dibutuhkan hanya angkanya. Endpoint ini yang dipakai untuk tab yang
datanya memang belum dimuat.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| from | date (YYYY-MM-DD) | Tidak | Awal rentang tanggal |
| to | date (YYYY-MM-DD) | Tidak | Akhir rentang tanggal |

Rentang tanggal mengikuti filter di halaman agar angka badge selalu sama dengan isi
daftarnya. Kolom yang dibandingkan berbeda per tahap:

| Angka | Status order | Kolom tanggal |
|---|---|---|
| `masuk` | `diajukan` | — (tidak disaring) |
| `siap_distribusi` | `digudang` | `processed_at` (saat diterima CSSD) |
| `dipinjam` | `dipinjam` | `order_date` (tanggal pinjam) |
| `dikembalikan` | `dikembalikan` | `updated_at` (perkiraan waktu pengembalian selesai) |

`masuk` sengaja TIDAK ikut disaring: order yang belum diterima harus selalu terlihat,
setua apa pun tanggalnya.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Jumlah order per tahap berhasil diambil.",
  "data": {
    "masuk": 3,
    "siap_distribusi": 2,
    "dipinjam": 7,
    "dikembalikan": 12
  }
}
```

Badge tab "Distribution & Tracking" = `siap_distribusi + dipinjam` — hanya pekerjaan
yang belum selesai. Order yang sudah dikembalikan tidak dihitung: riwayatnya tetap
tampil di daftar, tapi bukan lagi tugas berjalan.

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated. Silakan login terlebih dahulu."
}
```
