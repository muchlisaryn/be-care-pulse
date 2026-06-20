# update

**Method:** PUT / PATCH  
**Endpoint:** `/api/master/sterilizations/{sterilization}`  
**Controller:** `App\Http\Controllers\Transaction\SterilizationController@update`  
**Auth:** Bearer Token (wajib)

Memperbarui data batch dan/atau **mengubah status batch**. Perubahan status menyinkronkan
status seluruh unit instrumen dalam batch. Dijalankan dalam satu transaksi DB.

### Alur status & efek ke unit instrumen
| Status baru | Efek pada `instrument_stocks.status` |
|-------------|--------------------------------------|
| `diproses` | Semua unit → `sterilisasi` |
| `selesai` | Semua unit → `tersedia` (steril & siap pakai) |
| `gagal` | Semua unit → `sterilisasi` (harus diproses ulang) |

Umumnya hasil indikator biologis (`biological_indicator`) baru tersedia beberapa jam setelah
proses, jadi field ini biasanya diisi via update sebelum batch ditandai `selesai`.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| machine | string | Tidak | Nama/no. mesin |
| method | string | Tidak | `uap`, `eo`, `plasma`, `panas_kering` |
| cycle_number | string | Tidak | No. siklus |
| temperature | numeric | Tidak | Suhu (°C) |
| duration_minutes | integer | Tidak | Durasi (menit) |
| operator | string | Tidak | Operator |
| sterilized_at | date | Tidak | Waktu proses |
| expiry_date | date | Tidak | Masa berlaku steril |
| chemical_indicator | string | Tidak | Hasil indikator kimia |
| biological_indicator | string | Tidak | Hasil indikator biologis |
| note | string | Tidak | Catatan |
| status | string | Tidak | `diproses`, `selesai`, `gagal` |

### Contoh — Tandai batch selesai
```json
{
  "status": "selesai",
  "biological_indicator": "lulus"
}
```

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Batch sterilisasi berhasil diperbarui.",
  "data": {
    "id": 1,
    "code": "STR-001",
    "status": "selesai",
    "biological_indicator": "lulus",
    "items": [
      {
        "id": 1,
        "instrument_stock_id": 1,
        "instrument_stock": { "id": 1, "code": "ZHVQ-001", "status": "tersedia" }
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
    "status": ["The selected status is invalid."]
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
