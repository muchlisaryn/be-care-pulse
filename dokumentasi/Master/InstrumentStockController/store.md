# store

**Method:** POST
**Endpoint:** `/api/master/instrument-stocks`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@store`

## Request

### Body (JSON)
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| instrument_id | integer | Ya | ID instrumen, harus ada di tabel `instruments` |
| condition_id | integer | Tidak | ID kondisi, harus ada di tabel `conditions` |
| status | string | Tidak | Status unit: `tersedia` (default), `dipinjam`, `sterilisasi`, `dikembalikan` |
| quantity | integer | Tidak | Jumlah unit yang dibuat sekaligus, 1–100 (default 1) |

> `code` di-generate otomatis oleh backend mengikuti format `{kode_instrumen}-{urutan}`, contoh: `INSK-001`, `INSK-002`. Tidak perlu dikirim dari client.

> **`quantity`** membuat beberapa unit dalam satu request — mis. `3` menghasilkan 3 baris
> terpisah dengan kode berurutan (`INSK-001`, `-002`, `-003`), masing-masing memakai
> `condition_id` & `status` yang sama. Dibungkus satu transaksi: bila gagal di tengah,
> tidak ada unit yang tersimpan sebagian.

> **Bentuk respons:** `data` SELALU berupa **array** unit yang dibuat, termasuk saat
> hanya 1 unit.

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Stok instrumen berhasil ditambahkan.",
  "data": [{
    "id": 1,
    "instrument_id": 1,
    "code": "INSK-001",
    "condition_id": 1,
    "status": "tersedia",
    "created_by": "Admin",
    "updated_by": "Admin",
    "deleted_at": null,
    "deleted_by": null,
    "created_at": "2026-05-21T09:00:00.000000Z",
    "updated_at": "2026-05-21T09:00:00.000000Z",
    "instrument": { "id": 1, "code": "INS-001", "name": "Stetoskop" },
    "condition": { "id": 1, "name": "Baik" }
  }]
}
```

Saat `quantity` > 1, `message` menjadi `"{n} unit stok instrumen berhasil ditambahkan."`
dan `data` berisi n unit.

### Error (422)
```json
{
  "message": "The instrument id field is required.",
  "errors": {
    "instrument_id": ["The instrument id field is required."]
  }
}
```
