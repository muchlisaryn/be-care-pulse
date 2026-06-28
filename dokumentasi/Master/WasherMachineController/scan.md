# WasherMachineController@scan

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** POST
**Endpoint:** /api/master/washer-machines/scan
**Auth:** Bearer Token (wajib)

Scan barcode mesin washer: lookup mesin berdasarkan kode (WSH-NNN). Dipakai
petugas sebelum memasukkan alat ke mesin pencuci (Tahap 2 — Cleaning &
Disinfection). Hasil scan dipakai sebagai `washer_machine_id` saat menyimpan
catatan pencucian.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode/barcode mesin (mis. `WSH-001`) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Mesin washer ditemukan.",
  "data": {
    "id": 1,
    "code": "WSH-001",
    "name": "Washer Disinfector 1",
    "min_temperature": "55.00",
    "max_temperature": "93.00",
    "min_duration_minutes": 10,
    "max_duration_minutes": 30,
    "status": "aktif"
  }
}
```

#### Error (404)
```json
{ "status": false, "message": "Mesin washer dengan kode tersebut tidak ditemukan." }
```

#### Error (422) — mesin nonaktif
```json
{ "status": false, "message": "Mesin washer ini berstatus nonaktif dan tidak dapat digunakan." }
```
