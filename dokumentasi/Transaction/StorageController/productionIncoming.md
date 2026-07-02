# StorageController — productionIncoming

**Controller:** App\Http\Controllers\Transaction\StorageController
**Base URL:** /api/master

---

## 1. productionIncoming (Batch Produksi Siap-Simpan)

**Method:** GET
**Endpoint:** /api/master/storage/production-incoming
**Auth:** Bearer Token (wajib)

Daftar batch steril **pipeline produksi** yang perlu disimpan ke gudang: record
`sterilizations` berstatus `selesai` yang berasal dari packaging (punya
`packaging_code`) & **tanpa order** (`order_id` null). Bentuk respons sama dengan
`storage/incoming` (order) agar FE bisa memakai daftar & modal simpan yang sama —
dibedakan lewat `source` (`"produksi"`) dan `store_url`.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `code` (STR) atau `packaging_code` (PKG) |
| page | integer | Tidak | Halaman pagination (default 1) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data batch produksi siap-simpan berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 4,
        "code": "STR-003",
        "code_transaction": "PRD20260702001",
        "status": "steril",
        "source": "produksi",
        "store_url": "/master/sterilization/4/store",
        "borrowed_by": "SET PARTUS",
        "room": null,
        "processed_at": "2026-07-02T09:00:00.000000Z",
        "expiry_date": "2026-07-09",
        "unit_count": 6,
        "stored_count": 0,
        "units": [
          {
            "id": 86, "code": "GNE-001", "instrument": "Gunting Epis", "image_url": null,
            "source": "paket", "package_name": "SET PARTUS", "stored": false, "rack_code": null
          }
        ]
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

Catatan: batch yang seluruh unitnya sudah tersimpan (`stored_count == unit_count`)
disaring di sisi FE dari daftar "Perlu Disimpan".

#### Error (500)
```json
{ "status": false, "message": "pesan error asli dari exception", "code": 0, "file": "...", "line": 42 }
```
