# scan

**Method:** POST
**Endpoint:** `/api/master/instrument-stocks/scan`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@scan`
**Auth:** Bearer Token (wajib)

Mencari satu unit instrumen berdasarkan `code` hasil pemindaian QR Code (PRD F3 — Scan QR Code). Dipakai saat serah-terima (peminjaman/pengembalian) agar petugas tidak perlu mengetik kode manual.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode unit instrumen hasil scan QR (mis. `IBAP-001`) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Instrumen ditemukan.",
  "data": {
    "id": 1,
    "instrument_id": 1,
    "code": "IBAP-001",
    "condition_id": 1,
    "status": "tersedia",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-06-07T00:55:42.000000Z",
    "updated_at": "2026-06-07T00:55:42.000000Z",
    "instrument": { "id": 1, "code": "IBAP", "name": "Gunting Bedah" },
    "condition": { "id": 1, "name": "Baik" }
  }
}
```

### Error (404)
```json
{
  "status": false,
  "message": "Instrumen dengan kode tersebut tidak ditemukan."
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "code": ["The code field is required."] }
}
```
