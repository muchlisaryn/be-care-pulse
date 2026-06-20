# store

**Method:** POST  
**Endpoint:** `/api/master/sterilizations`  
**Controller:** `App\Http\Controllers\Transaction\SterilizationController@store`  
**Auth:** Bearer Token (wajib)

Membuat batch/siklus sterilisasi baru beserta daftar unit instrumen yang disterilkan.
Status awal selalu `diproses`, dan **semua unit dalam batch otomatis berubah status menjadi `sterilisasi`**.
Seluruh proses dibungkus dalam satu transaksi DB.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| machine | string | Ya | Nama/no. mesin autoclave |
| method | string | Tidak | Metode: `uap` (default), `eo`, `plasma`, `panas_kering` |
| cycle_number | string | Tidak | No. siklus pada mesin |
| temperature | numeric | Tidak | Suhu proses (°C) |
| duration_minutes | integer | Tidak | Durasi proses (menit) |
| operator | string | Tidak | Nama operator pelaksana |
| sterilized_at | date | Ya | Waktu proses sterilisasi |
| expiry_date | date | Tidak | Masa berlaku steril (harus ≥ `sterilized_at`) |
| chemical_indicator | string | Tidak | Hasil indikator kimia (mis. `lulus`/`gagal`) |
| biological_indicator | string | Tidak | Hasil indikator biologis (mis. `pending`/`lulus`/`gagal`) |
| note | string | Tidak | Catatan |
| items | array | Ya | Minimal 1 unit instrumen |
| items[].instrument_stock_id | integer | Ya | Unit fisik (unik per batch), harus ada di instrument_stocks |

### Contoh Body
```json
{
  "machine": "Autoclave-01",
  "method": "uap",
  "cycle_number": "C-12",
  "temperature": 134,
  "duration_minutes": 30,
  "operator": "Budi",
  "sterilized_at": "2026-06-08 08:00:00",
  "expiry_date": "2026-07-08",
  "chemical_indicator": "lulus",
  "items": [
    { "instrument_stock_id": 1 },
    { "instrument_stock_id": 2 }
  ]
}
```

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Batch sterilisasi berhasil dibuat.",
  "data": {
    "id": 1,
    "code": "STR-001",
    "machine": "Autoclave-01",
    "method": "uap",
    "status": "diproses",
    "expiry_date": "2026-07-08",
    "items": [
      {
        "id": 1,
        "sterilization_id": 1,
        "instrument_stock_id": 1,
        "result": null,
        "instrument_stock": {
          "id": 1, "code": "ZHVQ-001", "status": "sterilisasi",
          "instrument": { "id": 1, "code": "ZHVQ", "name": "stetoskop" }
        }
      }
    ]
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "machine": ["The machine field is required."],
    "items": ["The items field is required."]
  }
}
```

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
