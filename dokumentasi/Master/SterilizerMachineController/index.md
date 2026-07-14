# SterilizerMachineController@index

**Controller:** App\Http\Controllers\Master\SterilizerMachineController
**Method:** GET
**Endpoint:** /api/master/sterilizer-machines
**Auth:** Bearer Token (wajib)

Daftar master mesin sterilisator (autoclave), dipaginasi 20/halaman.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `name`, `code`, atau `location` |
| status | string | Tidak | Filter `aktif` / `nonaktif` |
| page | integer | Tidak | Nomor halaman |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data mesin sterilisator berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      { "id": 1, "code": "STL-001", "name": "Autoclave 1", "location": "Ruang Sterilisasi", "temperature": "134.00", "duration_minutes": 30, "sterile_shelf_life_days": 30, "status": "aktif", "note": null }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```
