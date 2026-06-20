# expiring

**Method:** GET  
**Endpoint:** `/api/master/sterilizations/expiring`  
**Controller:** `App\Http\Controllers\Transaction\SterilizationController@expiring`  
**Auth:** Bearer Token (wajib)

Daftar batch sterilisasi berstatus `selesai` yang masa berlaku sterilnya **sudah lewat atau akan
kadaluarsa** dalam ambang hari tertentu. Berguna untuk alert patient-safety: instrumen steril yang
kadaluarsa harus diproses ulang sebelum dipakai.

> Catatan: endpoint ini harus dideklarasikan sebelum `apiResource('sterilizations')` pada routes
> agar tidak tertangkap sebagai parameter `{sterilization}`.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| days | integer | Tidak | Ambang hari ke depan (default: 7). `0` = hanya yang sudah/akan kadaluarsa hari ini |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data sterilisasi mendekati/melewati kadaluarsa berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "STR-001",
        "machine": "Autoclave-01",
        "method": "uap",
        "status": "selesai",
        "sterilized_at": "2026-06-01T08:00:00.000000Z",
        "expiry_date": "2026-06-11",
        "items": [
          {
            "id": 1,
            "instrument_stock_id": 1,
            "instrument_stock": {
              "id": 1, "code": "ZHVQ-001", "status": "tersedia",
              "instrument": { "id": 1, "code": "ZHVQ", "name": "stetoskop" }
            }
          }
        ]
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
