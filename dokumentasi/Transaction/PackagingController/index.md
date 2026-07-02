# PackagingController — index

**Controller:** App\Http\Controllers\Transaction\PackagingController
**Base URL:** /api/master

---

## 1. index (Daftar Tahap Packaging)

**Method:** GET
**Endpoint:** /api/master/packaging
**Auth:** Bearer Token (wajib)

Daftar batch pada tahap **Inspection & Packaging** pipeline produksi: record
`packaging` (PKG-NNN) yang masih berstatus `diproses` (belum ditandai selesai).
Dirangkai ke tahap cleaning lewat `washing_code`, dan ke produksi lewat rantai
`washing.production_code`. Unit fisik sudah dikunci sejak tahap Produksi.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `code` (PKG), `washing_code` (WSH), atau `operator` |
| page | integer | Tidak | Halaman pagination (default 1) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data tahap packaging berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 3,
        "code": "PKG-003",
        "code_transaction": "PRD20260702004",
        "washing_code": "WSH-003",
        "status": "pengemasan",
        "borrowed_by": "SET PARTUS",
        "processed_at": "2026-07-02T08:00:00.000000Z",
        "processed_by": "Admin",
        "operator": null,
        "chemical_indicator": null,
        "packaged_at": null,
        "units_count": 6,
        "items": [
          { "type": "paket", "name": "SET PARTUS", "quantity": 6 }
        ],
        "units": [
          {
            "id": 21,
            "source": "paket",
            "package_name": "SET PARTUS",
            "instrument_stock_id": 18,
            "code": "GNT-001",
            "instrument": { "id": 7, "name": "Gunting Epis" },
            "status": "sterilisasi",
            "condition_out": { "id": 1, "name": "Baik" }
          }
        ]
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
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
