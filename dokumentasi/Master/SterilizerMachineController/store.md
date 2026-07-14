# SterilizerMachineController@store

**Controller:** App\Http\Controllers\Master\SterilizerMachineController
**Method:** POST
**Endpoint:** /api/master/sterilizer-machines
**Auth:** Bearer Token (wajib)

Tambah master mesin sterilisator. `code` (STL-NNN) di-generate otomatis.

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
| sterile_shelf_life_days | integer | Tidak | Masa simpan steril (hari, min 1) untuk alat yang disterilkan di mesin ini |
| status | string | Tidak | `aktif` (default) / `nonaktif` |
| note | string | Tidak | Catatan |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Mesin sterilisator berhasil ditambahkan.",
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
