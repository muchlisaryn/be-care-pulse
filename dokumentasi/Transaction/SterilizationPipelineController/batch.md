# SterilizationPipelineController — batch

**Controller:** App\Http\Controllers\Transaction\SterilizationPipelineController
**Base URL:** /api/master

---

## 1. batch (Buat Batch Sterilisasi Gabungan)

**Method:** POST
**Endpoint:** /api/master/sterilization-pipeline/batch
**Auth:** Bearer Token (wajib)

Membuat **satu** batch sterilisasi (STR) dari **beberapa** PKG siap-steril terpilih
(menggabungkan produksi satuan/paket yang disterilkan bersamaan). Seluruh unit tiap
PKG masuk ke batch (`sterilization_items`), unit → status `sterilisasi`, dan tiap PKG
ditandai `sterilization_id` batch tersebut. Batch berstatus `diproses` (menunggu validasi).

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| packaging_ids | array<int> | Ya | Daftar id PKG siap-steril yang digabung (min 1) |
| machine | string | Ya | Nama/nomor mesin sterilisator |
| method | string | Tidak | `uap` \| `eo` \| `plasma` \| `panas_kering` (default `uap`) |
| cycle_number | string | Tidak | Nomor siklus |
| temperature | numeric | Tidak | Suhu (°C) |
| duration_minutes | integer | Tidak | Durasi (menit) |
| operator | string | Tidak | Operator |
| sterilized_at | datetime | Ya | Waktu sterilisasi |
| expiry_date | date | Tidak | Kedaluwarsa (>= sterilized_at) |
| chemical_indicator | string | Tidak | Hasil indikator kimia |
| biological_indicator | string | Tidak | Hasil indikator biologis |
| note | string | Tidak | Catatan |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Batch sterilisasi berhasil dibuat.",
  "data": {
    "id": 5,
    "kind": "batch",
    "code": "STR-007",
    "status": "sterilisasi",
    "borrowed_by": "SET PARTUS, VC SET",
    "unit_count": 12,
    "sterilization": { "id": 5, "code": "STR-007", "machine": "Autoclave-01", "status": "diproses" }
  }
}
```

#### Error (422)
```json
{ "status": false, "message": "Tidak ada batch packaging siap-steril yang valid dipilih." }
```

#### Error (500)
```json
{ "status": false, "message": "pesan error asli dari exception", "code": 0, "file": "...", "line": 42 }
```
