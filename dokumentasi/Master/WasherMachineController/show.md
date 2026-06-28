# WasherMachineController@show

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** GET
**Endpoint:** /api/master/washer-machines/{washer_machine}
**Auth:** Bearer Token (wajib)

Detail satu mesin pencuci.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Detail mesin washer berhasil diambil.",
  "data": {
    "id": 1,
    "code": "WSH-001",
    "name": "Washer Disinfector 1",
    "location": "Ruang Dekontaminasi",
    "min_temperature": "55.00",
    "max_temperature": "93.00",
    "min_duration_minutes": 10,
    "max_duration_minutes": 30,
    "status": "aktif",
    "note": null
  }
}
```

#### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
