# index

**Method:** GET
**Endpoint:** `/api/master/instruments`
**Controller:** `App\Http\Controllers\Master\InstrumentController@index`

Setiap item menyertakan `image` (path relatif, `null` bila belum ada) dan `image_url`
(URL publik gambar, `null` bila belum ada). Kelola lewat `uploadImage` / `deleteImage`.

## Request

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan `name` atau `code` (like) |
| sort | string | Tidak | Urutkan berdasarkan jumlah unit stok: `stock_asc` (stok terkecil) / `stock_desc` (stok terbanyak) |
| page | integer | Tidak | Nomor halaman (default: 1) |

> Setiap item menyertakan `stocks_count` — jumlah unit fisik (stok) milik instrumen tersebut,
> dan `available_stocks_count` — jumlah unit yang berstatus `tersedia` (siap dipinjam).

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data instrumen berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "INS-001",
        "name": "Stetoskop",
        "stocks_count": 3,
        "available_stocks_count": 2,
        "created_by": "Admin",
        "updated_by": "Admin",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-05-21T09:00:00.000000Z",
        "updated_at": "2026-05-21T09:00:00.000000Z"
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
