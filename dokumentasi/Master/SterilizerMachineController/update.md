# SterilizerMachineController@update

**Controller:** App\Http\Controllers\Master\SterilizerMachineController
**Method:** PUT
**Endpoint:** /api/master/sterilizer-machines/{sterilizer_machine}
**Auth:** Bearer Token (wajib)

Perbarui data mesin sterilisator. `code` tidak berubah.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama mesin |
| location | string | Tidak | Lokasi penempatan |
| temperature | numeric | Tidak | Suhu standar mesin (°C) |
| duration_minutes | integer | Tidak | Durasi standar mesin (menit) |
| status | string | Tidak | `aktif` / `nonaktif` |
| note | string | Tidak | Catatan |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Mesin sterilisator berhasil diperbarui.",
  "data": { "id": 1, "code": "STL-001", "name": "Autoclave 1", "status": "aktif" }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "name": ["The name field is required."] }
}
```
