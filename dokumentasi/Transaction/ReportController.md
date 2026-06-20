# ReportController

**Controller:** App\Http\Controllers\Transaction\ReportController  
**Base URL:** /api/master/reports

---

## 1. cssdPerItem

Laporan CSSD Per Alat — satu baris per unit instrumen di setiap batch sterilisasi.
Sumber data: `SterilizationItem` → `Sterilization` + `InstrumentStock` (instrument & condition).
BMHP tidak termasuk (BMHP hanya didistribusi, tidak disterilkan).

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

Setiap item pada `data.data` berisi:
`id`, `name`, `unit_code`, `batch_code`, `status`, `method`, `machine`, `operator`,
`condition`, `result`, `sterilized_at`, `expiry_date`.

#### Success (200)
```json
{
  "status": true,
  "message": "Laporan CSSD per alat berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "name": "Gunting",
        "unit_code": "GNT-001",
        "batch_code": "STR-001",
        "status": "diproses",
        "method": "uap",
        "machine": "Autoclave",
        "operator": "-",
        "condition": "Baik",
        "result": null,
        "sterilized_at": "2026-06-12T08:09:00.000000Z",
        "expiry_date": "2026-06-15T00:00:00.000000Z"
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
