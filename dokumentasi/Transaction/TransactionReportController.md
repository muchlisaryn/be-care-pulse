# TransactionReportController

**Controller:** App\Http\Controllers\Transaction\TransactionReportController  
**Base URL:** /api/master/reports

Laporan Transaksi Instrumen — rekap peminjaman dengan **satu baris per label kemasan**
(`packaging_item.barcode_no`) di tiap transaksi.

Rantai data (berdiri sendiri, tidak memakai helper `ReportController`):

```
order → instrument_storages → production_item      (nama instrumen / nama set)
production → washing → packaging → packaging_item  (nomor barcode label)
```

- Nama alat diambil dari **snapshot** `production_item` (bukan master `instruments`),
  supaya laporan lama tidak ikut berubah saat master di-rename.
- Nomor barcode dicocokkan **per siklus** (`production.code` + `instrument_stock_id`),
  bukan "label terakhir unit" — satu unit fisik bisa melewati pipeline berkali-kali.
- Unit dalam satu set berbagi satu `barcode_no` sehingga lebur menjadi **satu baris**
  bernama nama setnya. Unit yang belum punya label tidak pernah digabung.

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
| room | string\|null | Nama ruangan peminjam |

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
        "room": "OK 1"
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
        "room": "OK 1"
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
