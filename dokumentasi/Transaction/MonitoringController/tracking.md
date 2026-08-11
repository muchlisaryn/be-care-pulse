# tracking

**Method:** GET  
**Endpoint:** `/api/master/monitoring/tracking`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@tracking`  
**Auth:** Bearer Token (wajib)

Daftar tab **Distribution & Tracking** halaman Tracking Order sebagai SATU daftar yang
dipaginasi di server: order yang masih **dipinjam** lebih dulu (pekerjaan berjalan),
lalu **riwayat** order yang sudah **dikembalikan**.

Sebelum endpoint ini ada, frontend menggabung [`monitoring/rooms`](rooms.md) +
[`monitoring/returned`](returned.md) lalu memotong hasilnya di klien. Karena kedua
endpoint itu sendiri dipaginasi (20 baris per halaman), "halaman 2" di layar hanya
memotong data yang kebetulan sudah terkirim — order di luar itu tidak pernah bisa
dibuka. Di sini urutan, penyaringan, dan potongan halamannya ditentukan server.

Kedua kelompok punya kolom tanggal & urutan yang berbeda, jadi paginasinya dirakit
manual: kelompok `dipinjam` dihitung dulu, sisa kuota halaman baru diambil dari
kelompok `dikembalikan`. Batas halaman tetap tepat (satu halaman bisa memuat sebagian
dari kedua kelompok) tanpa perlu UNION.

Grup **"Siap Distribusi"** (status `digudang`) TIDAK termasuk di sini — grup itu tampil
terpisah di atas daftar lewat endpoint distribusinya sendiri dan tidak dipaginasi.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada kode order, no. transaksi, nama peminjam, nama ruangan, nama/kode katalog instrumen, kode unit fisik, atau **nomor label kemasan** (`packaging_item.barcode_no`) — supaya hasil scan barcode langsung menemukan ordernya |
| from | date (Y-m-d) | Tidak | Batas awal tanggal aktivitas |
| to | date (Y-m-d) | Tidak | Batas akhir tanggal aktivitas (wajib ≥ `from`) |
| page | integer | Tidak | Nomor halaman (default: 1) |
| per_page | integer | Tidak | Baris per halaman (default: **30**, maksimal 100) |

Rentang tanggal dibandingkan dengan tanggal **aktivitas terakhir** tiap kelompok — sama
seperti [`counts`](counts.md) — supaya order lama yang baru dikembalikan tetap muncul
pada rentang "7 hari terakhir":

| Kelompok | Kolom tanggal | Urutan |
|---|---|---|
| `dipinjam` | `order_date` | `order_date` desc, `id` desc |
| `dikembalikan` | `updated_at` | `updated_at` desc, `id` desc |

## Response

Tiap baris punya `kind` yang menentukan bentuk isinya.

| Field | Type | Keterangan |
|-------|------|------------|
| kind | string | `borrowed` (masih dipinjam) atau `returned` (riwayat) |
| order_id | integer | Id order |
| order_code | string | Kode order (ORD-NNN) |
| instruments | array | **Hanya `borrowed`.** Baris unit yang masih dipinjam, dikelompokkan per (asal, nama paket, katalog instrumen) + `room` = nama ruangan. Bentuknya SAMA dengan `instruments` pada [`rooms`](rooms.md) sehingga pengelompok di frontend bisa dipakai ulang |
| order | object | **Hanya `returned`.** Ringkasan kartu riwayat — field-nya identik dengan baris [`returned`](returned.md) |

### Success (200)
```json
{
  "status": true,
  "message": "Data tracking distribusi berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "kind": "borrowed",
        "order_id": 12,
        "order_code": "ORD-012",
        "instruments": [
          {
            "order_code": "ORD-012",
            "code_transaction": "INV-007",
            "borrowed_by": "Ns. Rina",
            "patient_name": "SITI AMINAH",
            "medical_record_no": "000123",
            "order_date": "2026-07-19",
            "order_time": "08:05",
            "return_plan_date": "2026-07-21",
            "source": "paket",
            "package_name": "Set Bedah Minor",
            "package_sets": 2,
            "instrument": { "id": 3, "code": "GNT-01", "name": "Gunting Metzenbaum" },
            "qty": 2,
            "units": [
              {
                "instrument_stock_id": 11,
                "code": "GNT-01-001",
                "status": "dipinjam",
                "barcode_no": "PKG260719011",
                "condition": { "id": 1, "name": "Baik" }
              }
            ],
            "room": "OK 1"
          }
        ]
      },
      {
        "kind": "returned",
        "order_id": 9,
        "order_code": "ORD-009",
        "order": {
          "id": 9,
          "code": "ORD-009",
          "code_transaction": "INV-005",
          "status": "dikembalikan",
          "borrowed_by": "Ns. Budi",
          "patient_name": "SITI AMINAH",
          "medical_record_no": "000123",
          "room": { "id": 1, "name": "OK 1" },
          "order_date": "2026-07-15",
          "return_plan_date": "2026-07-17",
          "returned_at": "2026-07-17T09:12:44.000000Z",
          "total_units": 6,
          "total_sets": 1,
          "total_satuan": 1
        }
      }
    ],
    "last_page": 3,
    "per_page": 30,
    "total": 76
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "to": ["The to must be a date after or equal to from."]
  }
}
```

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
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
