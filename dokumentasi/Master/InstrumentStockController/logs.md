# logs

**Method:** GET  
**Endpoint:** `/api/master/instrument-stocks/{instrument_stock}/logs`  
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@logs`  
**Auth:** Bearer Token (wajib)

Riwayat (append-only) perubahan status sebuah unit instrumen, terbaru dulu. Setiap kali status unit
berubah — saat dibuat, manual, masuk peminjaman, atau sterilisasi — tercatat otomatis dengan konteks & referensinya.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| instrument_stock | integer | ID unit instrumen |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Riwayat pergerakan unit berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "instrument_stock_id": 1,
        "from_status": "sterilisasi",
        "to_status": "tersedia",
        "context": "sterilization",
        "reference_code": "STR-001",
        "note": null,
        "created_by": "Administrator",
        "created_at": "2026-06-08T09:00:00.000000Z",
        "updated_at": "2026-06-08T09:00:00.000000Z"
      },
      {
        "id": 8,
        "instrument_stock_id": 1,
        "from_status": "dipinjam",
        "to_status": "sterilisasi",
        "context": "sterilization",
        "reference_code": "STR-001",
        "note": null,
        "created_by": "Administrator",
        "created_at": "2026-06-08T08:30:00.000000Z",
        "updated_at": "2026-06-08T08:30:00.000000Z"
      },
      {
        "id": 1,
        "instrument_stock_id": 1,
        "from_status": null,
        "to_status": "tersedia",
        "context": "create",
        "reference_code": null,
        "note": null,
        "created_by": "Administrator",
        "created_at": "2026-06-08T08:00:00.000000Z",
        "updated_at": "2026-06-08T08:00:00.000000Z"
      }
    ],
    "per_page": 20,
    "total": 3,
    "last_page": 1
  }
}
```

**Nilai `context`:** `create` (saat unit dibuat), `manual` (diubah langsung via update stok), `order` (peminjaman/pengembalian), `sterilization` (proses sterilisasi).

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
