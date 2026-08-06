# TransactionReportController

**Controller:** App\Http\Controllers\Transaction\TransactionReportController  
**Base URL:** /api/master/reports

Laporan Transaksi Instrumen — rekap peminjaman dengan **satu baris per label kemasan**
(`packaging_item.barcode_no`) di tiap transaksi.

Rantai data (berdiri sendiri, tidak memakai helper `ReportController`):

```
order → instrument_storages → production_item      (nama instrumen / nama set)
production → washing → packaging → packaging_item  (nomor barcode label)
production.created_at                              (tanggal peminjaman)
order_events (type = dikembalikan)                 (jam pengembalian)
```

- Nama alat diambil dari **snapshot** `production_item` (bukan master `instruments`),
  supaya laporan lama tidak ikut berubah saat master di-rename.
- Nomor barcode dicocokkan **per siklus** (`production.code` + `instrument_stock_id`),
  bukan "label terakhir unit" — satu unit fisik bisa melewati pipeline berkali-kali.
- Unit dalam satu set berbagi satu `barcode_no` sehingga lebur menjadi **satu baris**
  bernama nama setnya. Unit yang belum punya label tidak pernah digabung.
- Nama baris ditentukan `production_item.source` dan **tidak saling menggantikan**:
  `paket` → `package_name`, `satuan` → `name`.
- Untuk `paket`, `package_name` dicari dari anggota **mana pun** yang terisi, bukan
  dari unit pertama saja. Nilainya tidak dijamin seragam di seluruh anggota satu
  set; bila yang kosong kebetulan unit pertama (urutan `st.id`), nama setnya hilang
  padahal anggota lain masih menyimpannya.
- `name` bernilai **null** bila seluruh anggota set memang tidak menyimpan nama set
  — barisnya tampil `—`. Nama instrumen sengaja tidak dipinjamkan ke baris paket:
  satu label paket memuat banyak instrumen, jadi menampilkan salah satunya sebagai
  nama set akan menyesatkan.

---

## 1. index

**Method:** GET  
**Endpoint:** /api/master/reports/transaksi-instrumen  
**Auth:** Bearer Token (wajib)

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada **nomor barcode** ATAU **nama instrumen/set** (case-insensitive, partial) |
| room_id | integer | Tidak | Filter ruangan peminjam — harus ada di `rooms.id` |
| type | string | Tidak | Filter jenis: `paket` (set) atau `satuan`. Kosong = semua. Disaring lewat `production_item.source` |
| status | string | Tidak | Status order. Default `dikembalikan`. Nilai valid = `Order::STATUSES` |
| date_from | date (Y-m-d) | Tidak | Tanggal transaksi paling awal (inklusif) |
| date_to | date (Y-m-d) | Tidak | Tanggal transaksi paling akhir (inklusif, wajib ≥ `date_from`) |
| page | integer | Tidak | Halaman, default 1 |
| per_page | integer | Tidak | Baris per halaman, default 20, maksimal 2000 (dipakai saat export) |

### Field pada tiap baris
| Field | Type | Keterangan |
|-------|------|------------|
| key | string | Kunci baris unik lintas transaksi (`orderId\|barcode`) — dipakai frontend sebagai React key |
| order_id | integer | Id transaksi asal |
| transaction_date | date | Tanggal transaksi (`order.order_date`) |
| invoice_no | string\|null | Nomor invoice (`order.code_transaction`); null bila order belum diproses |
| barcode_no | string\|null | Nomor label kemasan; null bila unit belum pernah dikemas |
| type | string | `paket` (satu set dalam satu bungkus) atau `satuan` |
| name | string\|null | Nama set (bila `paket`) atau nama instrumen (bila `satuan`) |
| borrowed_by | string\|null | Nama peminjam |
| borrowed_date | datetime\|null | Tanggal peminjaman = saat batch produksi unit ini dibuat (`production.created_at`). Tabel `production` tidak punya kolom `started_at` (dibuang di migration `2026_07_18_000008`) karena batch dibuat & unit dikunci dalam satu aksi — `created_at` memang waktu mulai produksinya |
| room | string\|null | Nama ruangan peminjam |
| medical_record_no | string\|null | No. RM pasien (`order.medical_record_no`); null bila alat belum ditautkan ke pasien |
| patient_name | string\|null | Nama pasien (`order.patient_name`); null bila belum ditautkan |
| returned_by | string\|null | Nama orang yang mengembalikan (`order.returned_by`) |
| return_date | date\|null | Tanggal pengembalian (`order.return_actual_date`) — hanya tanggal, tanpa jam |
| returned_at | datetime\|null | Momen persis pengembalian, dari event timeline `dikembalikan` (`order_events.created_at`). Bila satu order dikembalikan bertahap, yang dipakai adalah event TERAKHIR. Null pada order lama yang tidak punya event — frontend jatuh ke `return_date` |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Laporan transaksi instrumen berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "key": "12|PKG260719011",
        "order_id": 12,
        "transaction_date": "2026-07-19",
        "invoice_no": "INV-007",
        "barcode_no": "PKG260719011",
        "type": "paket",
        "name": "Set Bedah Minor",
        "borrowed_by": "Ns. Rina",
        "borrowed_date": "2026-07-19 08:05:00",
        "room": "OK 1",
        "medical_record_no": "00-12-3456",
        "patient_name": "Ahmad Fauzi",
        "returned_by": "Ns. Rina",
        "return_date": "2026-07-20",
        "returned_at": "2026-07-20 14:32:11"
      },
      {
        "key": "12|PKG260719012",
        "order_id": 12,
        "transaction_date": "2026-07-19",
        "invoice_no": "INV-007",
        "barcode_no": "PKG260719012",
        "type": "satuan",
        "name": "Gunting Metzenbaum",
        "borrowed_by": "Ns. Rina",
        "borrowed_date": "2026-07-19 08:05:00",
        "room": "OK 1",
        "medical_record_no": null,
        "patient_name": null,
        "returned_by": "Ns. Rina",
        "return_date": "2026-07-20",
        "returned_at": null
      }
    ],
    "last_page": 3,
    "per_page": 20,
    "total": 47
  }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "room_id": ["The selected room id is invalid."],
    "type": ["The selected type is invalid."]
  }
}
```

#### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
}
```

#### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
