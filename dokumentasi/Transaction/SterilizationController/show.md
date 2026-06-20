# show

**Method:** GET  
**Endpoint:** `/api/master/sterilizations/{sterilization}`  
**Controller:** `App\Http\Controllers\Transaction\SterilizationController@show`  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| sterilization | integer | ID batch sterilisasi |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Detail sterilisasi berhasil diambil.",
  "data": {
    "id": 1,
    "code": "STR-001",
    "machine": "Autoclave-01",
    "method": "uap",
    "cycle_number": "C-12",
    "temperature": "134.00",
    "duration_minutes": 30,
    "operator": "Budi",
    "sterilized_at": "2026-06-08T08:00:00.000000Z",
    "expiry_date": "2026-07-08",
    "chemical_indicator": "lulus",
    "biological_indicator": "lulus",
    "status": "selesai",
    "note": null,
    "items": [
      {
        "id": 1,
        "sterilization_id": 1,
        "instrument_stock_id": 1,
        "result": null,
        "instrument_stock": {
          "id": 1, "code": "ZHVQ-001", "status": "tersedia",
          "instrument": { "id": 1, "code": "ZHVQ", "name": "stetoskop" }
        }
      }
    ]
  }
}
```

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
