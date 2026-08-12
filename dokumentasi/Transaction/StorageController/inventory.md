# StorageController@inventory

**Controller:** App\Http\Controllers\Transaction\StorageController
**Method:** GET
**Endpoint:** /api/master/storage/inventory
**Auth:** Bearer Token (wajib)

Inventaris real-time gudang steril: unit yang sedang tersimpan + lokasi rak +
status kedaluwarsa. **Early-warning**: `alert = true` (merah) bila masa berlaku
steril ≤ ambang hari atau sudah lewat. Diurutkan dari yang paling cepat
kedaluwarsa.

**Filter baris:** memakai scope BERSAMA `InstrumentStorage::sterilePool()` — scope yang
sama dipakai `@summary` dan penyusun kandidat distribusi
(`OrderController::distributionCandidates`), supaya ketiganya mustahil menyimpang:

| Syarat | Keterangan |
|---|---|
| `deleted_by IS NULL` | baris gudang belum dihapus |
| `status = 'tersimpan'` | fisiknya masih di rak. Baris yang unitnya sudah **ditarik kembali ke produksi** (`status = keluar`, `order_id` tetap NULL) dulu ikut terpajang di sini sebagai stok — sekarang tidak lagi |
| `order_id IS NULL` | pool bebas, belum direservasi order manapun |

Konsekuensinya field `order` pada setiap baris selalu `null`, dan filter `search` per
kode order tidak pernah menghasilkan baris.

Kondisi unit (`instrument_stocks.status`) tetap **tidak** ikut menyaring — di mana pun,
termasuk saat distribusi.

**Penanda kelayakan distribusi:** tiap baris membawa `can_distribute` dan
`blocked_reason`. Baris yang tidak layak tetap **ditampilkan** (petugas perlu tahu
barangnya ada tapi harus diproses ulang), hanya diberi keterangan:

| `blocked_reason` | Kapan |
|---|---|
| `Tanpa tanggal kedaluwarsa` | `expiry_date` NULL — tidak bisa dijamin steril |
| `Kedaluwarsa` | `expiry_date` sudah lewat hari ini |
| `Sebungkus dengan unit kedaluwarsa` | ada unit lain di label kemasan yang sama yang kedaluwarsa/tanpa tanggal — sterilitas melekat pada bungkus, jadi seluruh isinya ikut gugur |

Aturannya sama persis dengan yang dipakai saat distribusi, jadi penanda di layar tidak
bisa berbeda dari kenyataan ketika tombol Distribusikan ditekan.

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

**Reservasi order:** karena daftar ini dikunci ke `instrument_storages.order_id` NULL,
field `order` pada setiap baris **selalu `null`** — unitnya masih pool produksi yang
bebas dialokasikan ke order berikutnya.

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
        "order": null
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
