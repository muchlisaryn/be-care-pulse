# qr

**Method:** GET
**Endpoint:** `/api/master/instrument-stocks/{id}/qr`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@qr`
**Auth:** Bearer Token (wajib)

Menghasilkan QR Code (format SVG) dari `code` unit instrumen (PRD F3 — Scan QR Code). QR dikembalikan sebagai **data URI base64** sehingga frontend cukup menaruhnya di `<img src="...">` untuk ditampilkan atau dicetak menjadi label.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID unit stok instrumen |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "QR Code instrumen berhasil dibuat.",
  "data": {
    "code": "IBAP-001",
    "qr_svg": "data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0..."
  }
}
```

> Isi `qr_svg` berupa gambar QR Code dari nilai `code`. Hasil scan QR ini sama dengan nilai yang dikirim ke endpoint `POST /api/master/instrument-stocks/scan`.

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\InstrumentStock]."
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
