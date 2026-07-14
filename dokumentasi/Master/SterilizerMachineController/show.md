# SterilizerMachineController@show

**Controller:** App\Http\Controllers\Master\SterilizerMachineController
**Method:** GET
**Endpoint:** /api/master/sterilizer-machines/{sterilizer_machine}
**Auth:** Bearer Token (wajib)

Detail satu mesin sterilisator.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Detail mesin sterilisator berhasil diambil.",
  "data": { "id": 1, "code": "STL-001", "name": "Autoclave 1", "location": "Ruang Sterilisasi", "temperature": "134.00", "duration_minutes": 30, "sterile_shelf_life_days": 30, "status": "aktif", "note": null }
}
```

#### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
