# OrderController@distributionOptions

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** GET
**Endpoint:** /api/master/orders/{order}/distribution-options
**Auth:** Bearer Token (wajib)

Pilihan barang yang bisa dikeluarkan dari gudang untuk sebuah order siap-distribusi
(status `digudang`). Dipakai modal **Distribusikan** agar petugas memilih sendiri stok
mana yang diambil dari rak, berdasarkan **kode produksi** (PRD-…, label pada bungkus steril).

Satu entri `requirements` = satu **baris permintaan order**, dan cara memilihnya mengikuti
bentuk baris itu:

| `kind` | Satu opsi berarti | `needed_qty` dihitung dalam |
|--------|-------------------|------------------------------|
| `satuan` | satu unit steril | unit |
| `paket` | satu **set paket utuh** dari satu batch produksi (`stock_ids` = seluruh isi paket) | paket |

Paket **tidak** dipilih per instrumen: memilih satu opsi paket otomatis mengambil semua unit
isi katalognya, dan seluruh isi berasal dari satu batch produksi (tidak tercampur antar batch).
Bila satu batch memproduksi beberapa set dengan nama paket sama, tiap set jadi opsi terpisah
(`set_index` = Set 1, Set 2, …).

## Syarat kandidat

Kandidat berangkat dari scope BERSAMA `InstrumentStorage::sterilePool()` — scope yang
sama dipakai daftar & ringkasan Inventaris Gudang Steril, jadi apa yang terlihat di
layar dan apa yang bisa didistribusikan tidak bisa lagi berbeda diam-diam:

| Syarat | Alasan |
|---|---|
| `instrument_storages.deleted_by IS NULL` | baris gudang belum dihapus |
| `instrument_storages.status = 'tersimpan'` | fisiknya masih di rak. Status BARIS GUDANG ini ditulis sekali saat unit keluar rak — didistribusikan **atau** ditarik kembali ke produksi (`ProductionController::closeStorageForReprocessed`) |
| `instrument_storages.order_id IS NULL` | masih pool bebas, belum diklaim order mana pun |

Ditambah syarat khusus distribusi:

- `expiry_date` **wajib ada** dan `>= hari ini`. Baris tanpa tanggal (`expiry_date`
  NULL) ditolak — bungkus tanpa tanggal kedaluwarsa tidak bisa dijamin steril.
- **Penolakan per bungkus:** bila satu baris dalam satu label kemasan
  (`sterilization_items.packaging_barcode`) kedaluwarsa atau tanpa tanggal, SELURUH isi
  label itu gugur (lihat `InstrumentStorage::blockedPackagingBarcodes()`). Sterilitas
  melekat pada bungkus, bukan pada unit — tanpa aturan ini, set masih bisa dirakit dari
  sisa isi bungkus yang fisiknya sudah tidak layak.
- Unit **tidak sedang dipegang order berjalan**: tidak ada `order_item` miliknya dengan
  `is_returned = false` pada order yang belum dibatalkan/dihapus.
- Bentuk simpannya cocok: satuan hanya dari unit yang disimpan satuan, paket hanya dari
  unit yang disimpan sebagai paket bernama sama. Urut FEFO.

**`instrument_stocks.status` TIDAK lagi menyaring.** Kolom itu ditulis ulang di banyak
titik sepanjang alur CSSD dan bisa tertinggal; dulu itulah yang membuat unit yang
jelas-jelas ada di rak ditolak dengan keterangan stok kosong. Penggantinya syarat
"tidak sedang dipegang order berjalan" di atas, yang dibaca dari jejak
`order_item.is_returned`.

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `digudang`) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Pilihan unit distribusi berhasil diambil.",
  "data": {
    "order": {
      "id": 10,
      "code": "ORD-004",
      "code_transaction": "INV20260712003",
      "borrowed_by": "Ns. Rina",
      "room": { "id": 2, "name": "OK 1" }
    },
    "requirements": [
      {
        "key": "line-31",
        "kind": "satuan",
        "name": "Klem Lurus",
        "needed_qty": 3,
        "unit_label": "unit",
        "options": [
          {
            "value": "u21",
            "production_code": "PRD-2606280001",
            "name": "Klem Lurus",
            "stock_ids": [21],
            "expiry_date": "2026-09-20",
            "rack_code": "RAK-A1"
          }
        ],
        "selected": ["u21"]
      },
      {
        "key": "line-32",
        "kind": "paket",
        "name": "Set Bedah Minor",
        "needed_qty": 1,
        "unit_label": "paket",
        "options": [
          {
            "value": "pPRD-2606280002#0",
            "production_code": "PRD-2606280002",
            "name": "Set Bedah Minor",
            "stock_ids": [30, 31, 32],
            "set_index": null,
            "expiry_date": null,
            "rack_code": null
          }
        ],
        "selected": ["pPRD-2606280002#0"]
      }
    ]
  }
}
```

`selected` = pilihan default modal (opsi yang unitnya sedang direservasi untuk order ini,
dilengkapi opsi FEFO teratas bila kurang). Frontend mengirim gabungan `stock_ids` dari opsi
terpilih sebagai `stock_ids` ke [distribute](distribute.md).

Catatan: unit paket tanpa kode batch produksi tidak ditawarkan, karena keutuhan satu set
per batch tidak bisa dijamin.

### Error (422)
```json
{ "status": false, "message": "Order ini belum berada di gudang steril / tidak siap didistribusikan." }
```
