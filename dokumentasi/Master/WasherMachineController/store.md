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
| max_temperature | numeric | Tidak | Ambang suhu maksimum (°C); wajib ≥ min_temperature **hanya bila** min_temperature diisi |
| min_duration_minutes | integer | Tidak | Ambang durasi minimum (menit) |
| max_duration_minutes | integer | Tidak | Ambang durasi maksimum (menit); wajib ≥ min_duration_minutes **hanya bila** min_duration_minutes diisi |
| sterile_shelf_life_days | integer | Tidak | Batas steril: masa simpan steril (hari, min 1) untuk alat yang dicuci di mesin ini; menentukan tanggal kedaluwarsa |
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
