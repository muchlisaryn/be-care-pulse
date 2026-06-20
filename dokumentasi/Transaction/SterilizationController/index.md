# index

**Method:** GET  
**Endpoint:** `/api/master/sterilizations`  
**Controller:** `App\Http\Controllers\Transaction\SterilizationController@index`  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan `code` batch atau `machine` (like) |
| status | string | Tidak | Filter status: `diproses`, `selesai`, `gagal` |
| method | string | Tidak | Filter metode: `uap`, `eo`, `plasma`, `panas_kering` |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data sterilisasi berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
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
        "items_count": 2,
        "created_by": "Administrator",
        "updated_by": "Administrator",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-06-08T08:00:00.000000Z",
        "updated_at": "2026-06-08T08:00:00.000000Z"
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
