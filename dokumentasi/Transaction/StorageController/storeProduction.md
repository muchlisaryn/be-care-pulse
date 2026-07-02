# StorageController — storeProduction

**Controller:** App\Http\Controllers\Transaction\StorageController
**Base URL:** /api/master

---

## 1. storeProduction (Simpan Batch Produksi ke Rak)

**Method:** POST
**Endpoint:** /api/master/sterilization/{sterilization}/store
**Auth:** Bearer Token (wajib)

Menyimpan unit-unit sebuah batch sterilisasi **pipeline produksi** (STR selesai,
tanpa order) ke lokasi rak gudang steril. Membuat baris `instrument_storages`
dengan `sterilization_id` (tanpa `order_id`), `expiry_date` disalin dari batch.

Unit tetap berstatus `tersedia` (invarian gudang) namun terkecuali dari pool
produksi karena baris gudang berstatus `tersimpan`. Unit yang sudah tersimpan
diabaikan (idempoten).

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| sterilization | integer | ID batch STR (status `selesai`, `order_id` null) |

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| items | array | Ya | Daftar unit + lokasi rak (min 1) |
| items[].instrument_stock_id | integer | Ya | ID unit (exists:instrument_stocks,id) |
| items[].rack_code | string | Ya | Kode/label lokasi rak |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Unit berhasil disimpan ke gudang steril.",
  "data": {
    "id": 4,
    "code": "STR-003",
    "source": "produksi",
    "unit_count": 6,
    "stored_count": 6,
    "units": [
      { "id": 86, "code": "GNE-001", "instrument": "Gunting Epis", "stored": true, "rack_code": "RAK-A-1" }
    ]
  }
}
```

#### Error (422) — bukan batch produksi steril
```json
{ "status": false, "message": "Batch ini bukan batch produksi yang steril / siap disimpan." }
```

#### Error (500)
```json
{ "status": false, "message": "pesan error asli dari exception", "code": 0, "file": "...", "line": 42 }
```
