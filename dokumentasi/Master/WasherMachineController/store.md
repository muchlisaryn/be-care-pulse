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
| min_temperature | numeric | Tidak | Ambang suhu minimum (°C) |
| max_temperature | numeric | Tidak | Ambang suhu maksimum (°C), ≥ min_temperature |
| min_duration_minutes | integer | Tidak | Ambang durasi minimum (menit) |
| max_duration_minutes | integer | Tidak | Ambang durasi maksimum (menit), ≥ min_duration_minutes |
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
