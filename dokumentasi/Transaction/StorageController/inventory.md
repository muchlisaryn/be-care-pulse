# StorageController@inventory

**Controller:** App\Http\Controllers\Transaction\StorageController
**Method:** GET
**Endpoint:** /api/master/storage/inventory
**Auth:** Bearer Token (wajib)

Inventaris real-time gudang steril: unit yang sedang tersimpan + lokasi rak +
status kedaluwarsa. **Early-warning**: `alert = true` (merah) bila masa berlaku
steril ≤ ambang hari atau sudah lewat. Diurutkan dari yang paling cepat
kedaluwarsa.

**Filter isi rak:** hanya baris gudang berstatus `tersimpan`, ber-`order_id` **NOT NULL**,
dan unitnya masih berkondisi `tersedia`. Unit yang sudah keluar gudang (sudah
didistribusikan → `dipinjam`, atau sedang diproses ulang → `sterilisasi`) tidak ikut
ditampilkan meski baris gudangnya masih `tersimpan`. Baris tersebut tetap tersimpan di
database — hanya disembunyikan dari daftar isi rak.

**Kenapa `order_id` harus terisi:** baris gudang lahir dengan `order_id = null` (stok
steril bebas hasil pipeline produksi). Saat order diterima, `OrderController@acceptDistribution`
memindahkan kepemilikan baris itu ke order (`order_id` terisi) sebagai reservasi —
statusnya masih `tersimpan` sampai benar-benar didistribusikan. Daftar ini hanya
menampilkan baris yang sudah terikat order tersebut, sehingga field `order` pada tiap
baris **selalu terisi** (tidak pernah `null`).

**Sumber nama instrumen:** `unit.code`, `unit.instrument`, `source`, dan `package_name`
diambil dari tabel `production_item` (snapshot batch produksi unit tersebut) lewat FK
`instrument_storages.production_item_id` — baris gudang tidak lagi menyimpan salinan
`source`/`package_name`. `unit.id` dibaca dari kolom `instrument_storages.instrument_stock_id`,
dan `instrument_stocks.code` / `instruments.name` dipakai sebagai cadangan bila snapshot
batch tidak mengisinya.

**Nomor label kemasan:** `barcode_no` adalah nomor label yang tercetak di bungkus
sterilnya, dibawa `sterilization_items.packaging_barcode` pada batch steril baris
gudang tersebut. Satu label = satu bungkus, jadi seluruh unit dalam satu set
berbagi nomor yang sama. Baris gudang lama tanpa `sterilization_id` memakai label
batch steril TERAKHIR unit itu.

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari kode unit, nama instrumen (production_item), nomor label kemasan, rak, atau order |
| days | integer | Tidak | Ambang early-warning (default 7) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Inventaris gudang steril berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 5,
        "rack_code": "RAK-A-2",
        "stored_at": "2026-06-28T09:10:00.000000Z",
        "expiry_date": "2026-07-02",
        "days_to_expiry": 4,
        "alert": true,
        "expired": false,
        "source": "paket",
        "package_name": "Set Minor Surgery",
        "barcode_no": "PKG202606280011",
        "production_code": "PRD-014",
        "unit": { "id": 87, "code": "GNE-002", "instrument": "Gunting Epis" },
        "order": { "id": 10, "code": "ORD-010", "code_transaction": "INV20260628001" }
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
