# StorageController@inventory

**Controller:** App\Http\Controllers\Transaction\StorageController
**Method:** GET
**Endpoint:** /api/master/storage/inventory
**Auth:** Bearer Token (wajib)

Inventaris real-time gudang steril: unit yang sedang tersimpan + lokasi rak +
status kedaluwarsa. **Early-warning**: `alert = true` (merah) bila masa berlaku
steril ≤ ambang hari atau sudah lewat. Diurutkan dari yang paling cepat
kedaluwarsa.

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari kode unit, instrumen, rak, atau order |
| days | integer | Tidak | Ambang early-warning (default 7) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Inventaris gudang steril berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 5,
        "rack_code": "RAK-A-2",
        "stored_at": "2026-06-28T09:10:00.000000Z",
        "expiry_date": "2026-07-02",
        "days_to_expiry": 4,
        "alert": true,
        "expired": false,
        "unit": { "id": 87, "code": "GNE-002", "instrument": "Gunting Epis" },
        "order": { "id": 10, "code": "ORD-010", "code_transaction": "INV20260628001" }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
