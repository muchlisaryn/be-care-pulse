# WasherMachineController@update

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** PUT/PATCH
**Endpoint:** /api/master/washer-machines/{washer_machine}
**Auth:** Bearer Token (wajib)

Perbarui data mesin pencuci. Body sama dengan store.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama mesin |
| location | string | Tidak | Lokasi penempatan |
| temperature | numeric | Tidak | Suhu standar mesin (°C); dipakai sebagai batas minimum deteksi kegagalan |
| duration_minutes | integer | Tidak | Durasi standar mesin (menit); dipakai sebagai batas minimum deteksi kegagalan |
| status | string | Tidak | `aktif` / `nonaktif` |
| note | string | Tidak | Catatan |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Mesin washer berhasil diperbarui.",
  "data": { "id": 1, "code": "WSH-001", "name": "Washer Disinfector 1 (Revisi)" }
}
```
