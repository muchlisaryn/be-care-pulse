# StorageController@incoming

**Controller:** App\Http\Controllers\Transaction\StorageController
**Method:** GET
**Endpoint:** /api/master/storage/incoming
**Auth:** Bearer Token (wajib)

Tahap 5 — Penyimpanan. Daftar order **steril** (status `steril`) yang perlu
disimpan ke rak gudang, beserta unit & status penempatan tiap unit + masa
kedaluwarsa (dari batch sterilisasi).

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari kode order, no. batch, peminjam, atau ruangan |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data order siap disimpan berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 10,
        "code": "ORD-010",
        "code_transaction": "INV20260628001",
        "status": "steril",
        "borrowed_by": "OK 1",
        "room": { "id": 3, "name": "OK 1" },
        "processed_at": "2026-06-28T08:30:00.000000Z",
        "expiry_date": "2026-12-28",
        "unit_count": 6,
        "stored_count": 0,
        "units": [
          { "id": 87, "code": "GNE-002", "instrument": "Gunting Epis", "barcode_no": "PKG260722012", "source": "paket", "package_name": "SET PARTUS", "stored": false, "rack_code": null }
        ]
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
