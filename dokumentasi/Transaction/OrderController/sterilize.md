# OrderController@sterilize

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/sterilize
**Auth:** Bearer Token (wajib)

Buat batch sterilisasi **langsung dari sebuah order** yang siap (status `selesai`).
Seluruh unit fisik order (order_item yang belum dikembalikan) dimasukkan ke batch:
- unit → status `sterilisasi`
- order → status `sterilisasi` (keluar dari antrean siap-steril)
- batch tercatat di modul Sterilisasi (`STR-NNN`) seperti biasa
- event timeline `disterilkan` dicatat

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `selesai`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| machine | string | Ya | Nama / no. mesin sterilisator |
| method | string | Tidak | `uap` (default) / `eo` / `plasma` / `panas_kering` |
| cycle_number | string | Tidak | Nomor siklus mesin |
| temperature | numeric | Tidak | Suhu (°C) |
| duration_minutes | integer | Tidak | Durasi (menit) |
| operator | string | Tidak | Operator pelaksana |
| sterilized_at | date | Ya | Waktu proses sterilisasi |
| expiry_date | date | Tidak | Masa berlaku steril (≥ sterilized_at) |
| chemical_indicator | string | Tidak | Hasil indikator kimia |
| biological_indicator | string | Tidak | Hasil indikator biologis |
| note | string | Tidak | Catatan |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Batch sterilisasi berhasil dibuat dari order.",
  "data": {
    "order_id": 12,
    "order_status": "sterilisasi",
    "sterilization": {
      "id": 7,
      "code": "STR-007",
      "machine": "Autoclave-01",
      "method": "uap",
      "status": "diproses",
      "items": [ { "instrument_stock_id": 101 } ]
    }
  }
}
```

#### Error (422) — belum siap
```json
{ "status": false, "message": "Order ini belum siap disterilkan (harus selesai packaging)." }
```

#### Error (422) — tidak ada unit
```json
{ "status": false, "message": "Order ini tidak punya unit untuk disterilkan." }
```
