# ReportController

**Controller:** App\Http\Controllers\Transaction\ReportController  
**Base URL:** /api/master/reports

---

## 1. cssdPerItem

Laporan CSSD Per Alat — dikelompokkan per batch sterilisasi. Item **paket** ditampilkan
sebagai **satu baris** (gabungan unit dalam paket itu) dengan rincian tiap aset di
`units`; instrumen **satuan** tetap satu baris per unit. Sumber data: `SterilizationItem`
→ `Sterilization` + `InstrumentStock`; asal (satuan/paket) & nama paket diambil dari
`order_item` batch tersebut. BMHP tidak termasuk (hanya didistribusi, tidak disterilkan).

Pengelompokan dilakukan di server lalu dipaginasi per-grup (agar paket tidak terpotong
antar halaman).

**Method:** GET  
**Endpoint:** /api/master/reports/cssd-per-item  
**Auth:** Bearer Token (wajib)

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada nama instrumen atau kode unit (mis. `GNT-001`) |
| status | string | Tidak | Filter status batch: `diproses` / `selesai` / `gagal` |
| method | string | Tidak | Filter metode steril: `uap` / `eo` / `plasma` / `panas_kering` |
| date_from | date | Tidak | Tanggal sterilisasi (`sterilized_at`) ≥ tanggal ini |
| date_to | date | Tidak | Tanggal sterilisasi (`sterilized_at`) ≤ tanggal ini |
| page | integer | Tidak | Halaman pagination |
| per_page | integer | Tidak | Jumlah per halaman (default 20, maks 2000 — dipakai saat export) |

### Response

Setiap baris pada `data.data` adalah satu **grup**:
- `key`, `type` (`paket` / `satuan`), `name` (nama paket atau nama instrumen),
- `unit_code`, `condition` (hanya untuk `satuan`; `null` untuk header paket),
- `batch_code`, `status`, `method`, `machine`, `operator`, `sterilized_at`, `expiry_date` (tingkat batch),
- `qty` (jumlah aset), `units[]` (rincian tiap aset: `id`, `name`, `unit_code`, `condition`, `result`).

#### Success (200)
```json
{
  "status": true,
  "message": "Laporan CSSD per alat berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "key": "pkg|3|SET PARTUS",
        "type": "paket",
        "name": "SET PARTUS",
        "unit_code": null,
        "condition": null,
        "batch_code": "STR-001",
        "status": "selesai",
        "method": "uap",
        "machine": "Autoclave-01",
        "operator": "-",
        "sterilized_at": "2026-06-30T08:09:00.000000Z",
        "expiry_date": "2026-07-07T00:00:00.000000Z",
        "qty": 2,
        "units": [
          { "id": 10, "name": "Gunting Epis", "unit_code": "GNE-001", "condition": "Baik", "result": "steril" },
          { "id": 11, "name": "Kom Kecil", "unit_code": "KMK-001", "condition": "Baik", "result": "steril" }
        ]
      },
      {
        "key": "unit|22",
        "type": "satuan",
        "name": "Bengkok",
        "unit_code": "BKK-003",
        "condition": "Baik",
        "batch_code": "STR-002",
        "status": "selesai",
        "method": "uap",
        "machine": "Autoclave-01",
        "operator": "-",
        "sterilized_at": "2026-06-30T09:00:00.000000Z",
        "expiry_date": "2026-07-07T00:00:00.000000Z",
        "qty": 1,
        "units": [
          { "id": 22, "name": "Bengkok", "unit_code": "BKK-003", "condition": "Baik", "result": "steril" }
        ]
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 2
  }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "status": ["The selected status is invalid."] }
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
