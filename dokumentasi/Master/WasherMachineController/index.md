# WasherMachineController@index

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** GET
**Endpoint:** /api/master/washer-machines
**Auth:** Bearer Token (wajib)

Daftar master mesin pencuci (washer disinfector) untuk tahap Cleaning & Disinfection.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `name`, `code`, atau `location` |
| status | string | Tidak | Filter `aktif` / `nonaktif` |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data mesin washer berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "WSH-001",
        "name": "Washer Disinfector 1",
        "location": "Ruang Dekontaminasi",
        "temperature": "60.00",
        "duration_minutes": 20,
        "status": "aktif"
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
