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
> `available_sterile_count` — jumlah unit STERIL siap-order SATUAN.
>
> Kriterianya **wajib sama persis dengan syarat distribusi**
> (`OrderController::distributionCandidates`), karena angka ini adalah janji ke pemesan:
> scope `InstrumentStorage::sterilePool()` (`deleted_by` NULL + `status = 'tersimpan'` +
> `order_id` NULL), **tanggal kedaluwarsa wajib ada dan belum lewat**, **bungkusnya tidak
> berisi unit kedaluwarsa** (`InstrumentStorage::blockedPackagingBarcodes()`), dan
> **diproduksi & disimpan sebagai satuan** (`production_item.source = satuan`).
> Begitu syarat di sini lebih longgar, form order menjanjikan barang yang nanti ditolak
> sendiri saat mau dikeluarkan dari gudang — itu yang dulu terjadi: baris kedaluwarsa dan
> baris tanpa tanggal ikut terhitung siap-order.
>
> Berbeda dengan tab **Inventaris** Gudang Steril (`SterileInventoryController@index`)
> yang justru TETAP menampilkan baris kedaluwarsa — di sana ditandai
> `can_distribute = false`, di sini tidak dihitung sama sekali.
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
