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
> `available_stocks_count` — jumlah unit yang berstatus `tersedia`, dan
> `available_sterile_count` — jumlah unit STERIL siap-order SATUAN (ada di gudang steril,
> `instrument_storages.status = tersimpan`, belum kedaluwarsa, **diproduksi & disimpan
> sebagai satuan** — `instrument_storages.source = satuan`, **dan masih milik
> produksi / belum dialokasikan ke order peminjaman** — order pemilik `room_id` null).
> Unit yang diproduksi sebagai PAKET tidak dihitung di sini: bentuk barang ditentukan
> saat produksi, sehingga hanya bisa dipinjam sebagai paket utuh (lihat
> `available_sterile_sets` pada InstrumentCatalogController).
> Begitu order menerima & mengalokasikan unit (FEFO), kepemilikan baris gudang pindah
> ke order itu sehingga otomatis keluar dari hitungan ini. Order hanya boleh atas barang steril.

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
        "available_sterile_count": 1,
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
