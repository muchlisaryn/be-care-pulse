# OrderTrackingController

**Controller:** App\Http\Controllers\Transaction\OrderTrackingController  
**Base URL:** /api/master/order-tracking

---

## 1. latest

**Method:** GET  
**Endpoint:** `/api/master/order-tracking/{order}/latest`  
**Auth:** Bearer Token (wajib)

**Aktivitas terakhir** sebuah order — bagian "Tracking" pada modal Pengembalian
Instrumen & Riwayat Pengembalian (halaman Tracking Order) saat pertama dibuka.

Endpoint ini **sengaja berdiri sendiri**, tidak digabung dengan endpoint lain:
modal itu hanya memajang satu baris (posisi order saat ini), jadi tidak boleh ikut
menanggung biaya perakitan timeline penuh. Riwayat lengkapnya baru ditarik saat
tombol **"Tampilkan semua tracking"** ditekan, lewat endpoint tersendiri
`GET /api/master/orders/{order}/timeline`.

Bedanya jauh: timeline penuh menelusuri seluruh pipeline CSSD tiap unit
(produksi → cleaning → sterilisasi → gudang), sedangkan di sini cukup **satu baris
teratas** dari tabel `order_events`.

Order hasil **pinjam-alih** berbagi satu `code_transaction`; timeline mereka satu
rangkaian, jadi event terbaru dicari lintas rantai itu — sama seperti timeline penuh.

Yang dikembalikan adalah event siklus order terbaru (`dibuat` → `diterima` →
`terdistribusi` → `dipinjam` → `dipindah` → `dikembalikan`). Untuk order yang sudah
didistribusikan — satu-satunya keadaan yang dipakai kedua modal itu — event inilah
yang juga menempati posisi terakhir pada timeline penuh, karena seluruh tahap
pipeline CSSD terjadi sebelum alat diantar.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| order | integer | Ya | ID order (route model binding) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Aktivitas terakhir order berhasil diambil.",
  "data": {
    "event": {
      "id": 20,
      "type": "dipinjam",
      "room": "AN - NAJMI",
      "actor": "Administrator",
      "borrowed_by": "Administrator",
      "note": "Order peminjaman diajukan · RM 21312313",
      "created_at": "2026-08-11T15:10:40.000000Z",
      "detail": null
    },
    "has_more": true
  }
}
```

| Field | Keterangan |
|-------|------------|
| event | Satu event terbaru — bentuknya sama persis dengan elemen `timeline` pada endpoint timeline penuh, supaya komponen frontend yang sama bisa merendernya. `null` bila order belum punya event sama sekali |
| event.detail | Selalu `null` — tombol "Detail" tahap pipeline hanya ada di timeline penuh |
| has_more | Masih ada riwayat lain di baliknya → dasar munculnya tombol "Tampilkan semua tracking". Sengaja **bukan jumlah pasti**: menghitung baris timeline penuh berarti merakitnya, persis yang dihindari endpoint ini |

#### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated. Silakan login terlebih dahulu."
}
```

#### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
