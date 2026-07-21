# SterilizationPipelineController — index

**Controller:** App\Http\Controllers\Transaction\SterilizationPipelineController
**Base URL:** /api/master

---

## 1. index (Daftar Pipeline Sterilisasi Produksi)

**Method:** GET
**Endpoint:** /api/master/sterilization-pipeline
**Auth:** Bearer Token (wajib)

Daftar pipeline sterilisasi produksi, gabungan tiga jenis item:
- **`kind: "ready"`, `reprocess: false`** — PKG (tray) selesai packaging yang **belum masuk batch** (`sterilization_id` null). `id` = id PKG (dipakai untuk memilih ke batch).
- **`kind: "ready"`, `reprocess: true`** — **unit re-proses lepas**: unit yang gagal steril (`sterilization_item` terbarunya `gagal`), kembali antre sebagai satu unit terpisah dari tray asalnya. `id` = id sintetis (1_000_000_000 + `stock_id`), field `stock_id` = `instrument_stock_id` (dikirim sbg `reproc_stock_ids` saat mem-batch). Otomatis hilang begitu unit di-batch ulang.
- **`kind: "batch"`** — batch STR dari pipeline produksi. Mencakup yang **menunggu validasi** (`sterilization.status = diproses`) **dan** yang sudah divalidasi sebagai **riwayat** (`sterilization.status = selesai`/`gagal`) — agar batch steril tidak hilang setelah divalidasi. `id` = id STR (dipakai untuk validasi saat `diproses`). Field `units[].result` = hasil per unit (`berhasil`/`gagal`/null). Bedakan menunggu-validasi vs riwayat lewat `sterilization.status`.

Respons dibentuk seperti paginator satu halaman.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari kode PKG/WSH (ready) atau kode STR (batch) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data pipeline sterilisasi berhasil diambil.",
  "data": {
    "data": [
      {
        "id": 5,
        "kind": "batch",
        "code": "STR-007",
        "code_transaction": "PRD20260702001, PRD20260702004",
        "status": "sterilisasi",
        "borrowed_by": "SET PARTUS, VC SET",
        "image_url": null,
        "processed_at": "2026-07-02T10:00:00.000000Z",
        "unit_count": 12,
        "units": [
          { "id": 21, "code": "GNE-001", "instrument": "Gunting Epis", "image_url": null, "source": "paket", "package_name": "SET PARTUS" }
        ],
        "sterilization": { "id": 5, "code": "STR-007", "machine": "Autoclave-01", "method": "uap", "status": "diproses" }
      },
      {
        "id": 8,
        "kind": "ready",
        "code": "PKG26071908",
        "code_transaction": "PRD20260702009",
        "status": "selesai",
        "borrowed_by": "HECTING SET",
        "image_url": null,
        "unit_count": 6,
        "units": [ ... ],
        "sterilization": null
      }
    ],
    "current_page": 1,
    "last_page": 1,
    "per_page": 2,
    "total": 2
  }
}
```

#### Error (500)
```json
{ "status": false, "message": "pesan error asli dari exception", "code": 0, "file": "...", "line": 42 }
```
