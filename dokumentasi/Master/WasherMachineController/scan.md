# WasherMachineController@scan

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** POST
**Endpoint:** /api/master/washer-machines/scan
**Auth:** Bearer Token (wajib)

Lookup mesin washer berdasarkan id. Menggantikan scan barcode lama yang mencari
lewat kode `WSH-NNN` — kolom kode sudah dihapus dari master. Dipakai sebelum
memasukkan alat ke mesin pencuci (Tahap 2 — Cleaning & Disinfection); hasilnya
dipakai sebagai `washer_machine_id` saat menyimpan catatan pencucian.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| washer_machine_id | integer | Ya | Id mesin washer (mis. `1`) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Mesin washer ditemukan.",
  "data": {
    "id": 1,
    "name": "Washer Disinfector 1",
    "temperature": "60.00",
    "duration_minutes": 20,
    "status": "aktif"
  }
}
```

#### Error (404)
```json
{ "status": false, "message": "Mesin washer tidak ditemukan." }
```

#### Error (422) — mesin nonaktif
```json
{ "status": false, "message": "Mesin washer ini berstatus nonaktif dan tidak dapat digunakan." }
```
