# WasherMachineController@store

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** POST
**Endpoint:** /api/master/washer-machines
**Auth:** Bearer Token (wajib)

Tambah master mesin pencuci. `code` (barcode WSH-NNN) di-generate otomatis.

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
| status | string | Tidak | `aktif` (default) / `nonaktif` |
| note | string | Tidak | Catatan |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Mesin washer berhasil ditambahkan.",
  "data": { "id": 1, "code": "WSH-001", "name": "Washer Disinfector 1", "status": "aktif" }
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
