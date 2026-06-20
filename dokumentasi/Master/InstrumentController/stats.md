# stats

**Method:** GET
**Endpoint:** `/api/master/instruments/stats`
**Controller:** `App\Http\Controllers\Master\InstrumentController@stats`
**Auth:** Bearer Token (wajib)

Mengembalikan ringkasan statistik instrumen untuk kartu dashboard di halaman master instrumen.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Statistik instrumen berhasil diambil.",
  "data": {
    "total_instruments": 24,
    "total_units": 118,
    "available_units": 95
  }
}
```

| Field | Keterangan |
|-------|------------|
| total_instruments | Jumlah jenis/katalog instrumen |
| total_units | Jumlah seluruh unit fisik (stok) |
| available_units | Jumlah unit dengan `status = tersedia` |
