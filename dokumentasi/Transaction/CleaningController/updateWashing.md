# CleaningController@updateWashing

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** PUT
**Endpoint:** /api/master/cleaning/{order}/washing
**Auth:** Bearer Token (wajib)

Menyimpan / memperbarui catatan pencucian sebuah order pada tahap cleaning.
Bila `complete = true`, catatan ditandai selesai (`Selesai Cuci`) dan order
lanjut ke status `pengemasan` (mencatat event `selesai_cuci`).

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `pencucian`/`pengemasan`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| machine_no | string | Tidak | Nomor mesin pencuci |
| operator | string | Tidak | ID / nama operator |
| temperature | string | Tidak | Suhu pencucian (°C) |
| washed_at | date | Tidak | Waktu pencucian |
| detergent_type | string | Tidak | Jenis deterjen / enzimatis |
| complete | boolean | Tidak | Tandai "Selesai Cuci" |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Catatan pencucian berhasil disimpan.",
  "data": {
    "id": 12,
    "status": "pengemasan",
    "washing": {
      "status": "selesai",
      "machine_no": "M-01",
      "operator": "OP-7",
      "temperature": "60",
      "washed_at": "2026-06-25T08:30:00.000000Z",
      "detergent_type": "Enzimatik",
      "completed_at": "2026-06-25T08:45:00.000000Z"
    }
  }
}
```

#### Error (422)
```json
{ "status": false, "message": "Order ini tidak sedang dalam tahap cleaning." }
```
