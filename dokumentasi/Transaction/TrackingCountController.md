# TrackingCountController

**Controller:** App\Http\Controllers\Transaction\TrackingCountController  
**Base URL:** /api/master/tracking-order

---

## 1. counts

**Method:** GET  
**Endpoint:** `/api/master/tracking-order/counts`  
**Auth:** Bearer Token (wajib)

Angka badge tab **Distribution & Tracking** pada halaman Tracking Order
(`/cssd/tracking-order?tab=distribusi`).

Endpoint ini **dipisah** dari `GET /api/master/monitoring/counts`
(`MonitoringController@counts`) yang masih menyuplai badge tab "Order Masuk".
Keduanya sengaja berdiri sendiri supaya perubahan aturan hitung di satu tab tidak
menggeser angka di tab lain.

### Aturan hitung — jejak waktu, bukan `status`

Kolom `status` ditulis ulang di banyak titik sepanjang alur CSSD dan bisa
tertinggal kalau ada satu proses yang gagal memperbaruinya. Karena itu angka di
sini dibaca dari kolom audit/jejak waktu yang masing-masing hanya ditulis sekali
tepat saat kejadiannya:

| Angka | Kondisi |
|---|---|
| `siap_distribusi` | `processed_at IS NOT NULL` **AND** `distributed_at IS NULL` |
| `dipinjam` | `distributed_at IS NOT NULL` **AND** masih ada `order_item.is_returned = false` |

Syarat yang berlaku untuk keduanya:

- `canceled_at IS NULL` — order batal dikeluarkan lewat jejaknya sendiri, bukan status.
- `deleted_by IS NULL` — otomatis dari global scope `active` (trait `HasAuditColumns`),
  termasuk untuk `order_item` pada pengecekan unit belum kembali.
- Rentang tanggal disaring pada `order_date` (tanggal pinjam) untuk **kedua** angka.

Kedua angka **saling lepas**: order yang sudah diantar keluar dari `siap_distribusi`,
order yang seluruh unitnya sudah kembali keluar dari `dipinjam` — tidak ada order
yang terhitung dua kali.

"Belum kembali" dibaca per unit (`order_item.is_returned`), bukan dari
`return_actual_date` di header order: pengembalian boleh dicicil, jadi tanggal di
header sudah terisi meski sebagian unit masih berada di ruangan.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| from | date (YYYY-MM-DD) | Tidak | Awal rentang `order_date` |
| to | date (YYYY-MM-DD) | Tidak | Akhir rentang `order_date`, harus ≥ `from` |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Jumlah order tab Distribution & Tracking berhasil diambil.",
  "data": {
    "siap_distribusi": 2,
    "dipinjam": 7,
    "total": 9
  }
}
```

`total` = `siap_distribusi + dipinjam` — angka yang dipajang badge. Dijumlahkan di
server supaya aturannya hanya ada di satu tempat.

#### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated. Silakan login terlebih dahulu."
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "to": ["The to field must be a date after or equal to from."] }
}
```
