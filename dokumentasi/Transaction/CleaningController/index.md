# CleaningController@index

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** GET
**Endpoint:** /api/master/cleaning
**Auth:** Bearer Token (wajib)

Daftar batch pada tahap Cleaning, beserta catatan pencucian & ringkasan
permintaan. Mencakup batch yang **masih diproses** maupun yang **sudah selesai**
cuci (riwayat/sudah lanjut ke packaging) — dibedakan lewat field `stage_status`
(`proses` | `selesai`). Mendukung `search` + pagination (20/halaman).

> `items` = baris permintaan (jenis + jumlah). `units` = unit fisik yang dikunci
> ke batch (kode stock, instrumen, kondisi). Untuk batch **Produksi CSSD** (`PRD-NNN`)
> `units` sudah terisi sejak awal; untuk order peminjaman biasa `units` masih kosong
> karena unit fisik baru di-generate di tahap Packaging. Field yang sama (`units`,
> `units_count`) juga muncul di response `alerts`, `process`, dan `updateWashing`
> karena berbagi `transform()` yang sama.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada code / borrowed_by / nama ruangan |
| page | integer | Tidak | Halaman |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data order tahap cleaning berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "code": "ORD-012",
        "status": "pencucian",
        "stage_status": "proses",
        "borrowed_by": "Ruang OK",
        "room": { "id": 3, "name": "OK 1" },
        "processed_at": "2026-06-25T08:00:00.000000Z",
        "requested_qty": 5,
        "request_lines": 2,
        "items": [{ "type": "satuan", "name": "Gunting", "quantity": 2 }],
        "units_count": 2,
        "units": [
          {
            "id": 45,
            "source": "satuan",
            "package_name": null,
            "instrument_stock_id": 88,
            "code": "GNT-001",
            "instrument": { "id": 7, "name": "Gunting" },
            "status": "sterilisasi",
            "condition_out": { "id": 1, "name": "Baik" }
          }
        ],
        "washing": { "status": "dalam_proses", "machine_no": "M-01", "temperature": "60", "...": null }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
