# CleaningController@alerts

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** GET
**Endpoint:** /api/master/cleaning/alerts
**Auth:** Bearer Token (wajib)

Daftar notifikasi kegagalan suhu/waktu pencucian: order pada tahap cleaning yang
catatan pencuciannya memiliki `alert = true` (suhu/durasi di luar ambang mesin
washer). Dipakai untuk panel notifikasi Tahap 2.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada kode order, peminjam, atau nama ruangan |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Daftar notifikasi kegagalan pencucian berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "code": "ORD-012",
        "status": "pencucian",
        "washing": {
          "washer_machine": { "id": 1, "name": "Washer Disinfector 1" },
          "temperature": "45",
          "duration_minutes": 8,
          "status": "dalam_proses",
          "alert": true,
          "alert_message": "Suhu 45°C di bawah minimum mesin (55°C). Durasi 8 menit di bawah minimum mesin (10 menit)."
        }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
