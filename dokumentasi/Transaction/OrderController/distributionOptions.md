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

Kandidat = unit yang sudah direservasi untuk order ini (alokasi FEFO saat order diterima) +
unit steril milik produksi yang masih bebas. Urut FEFO; unit kedaluwarsa tidak ikut.

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
