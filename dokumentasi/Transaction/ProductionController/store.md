# ProductionController — store

**Controller:** App\Http\Controllers\Transaction\ProductionController
**Base URL:** /api/master

---

## 1. store (Mulai Produksi)

**Method:** POST
**Endpoint:** /api/master/production
**Auth:** Bearer Token (wajib)

Awal lifecycle pemrosesan CSSD. CSSD memproses stok alat miliknya sendiri (tanpa
order peminjam) dan langsung memasukkannya ke antrean **Cleaning**. Membuat order
INTERNAL (`room_id` null, `borrowed_by` = "Produksi CSSD") berstatus `pencucian`,
sehingga mengalir ke pipeline yang ada: Cleaning → Packaging → Sterilization →
Storage.

**Pemotongan stok (saat Mulai Produksi):** stok langsung dipotong. Untuk tiap
baris, sistem memilih sejumlah unit `InstrumentStock` berstatus `tersedia`
(paket diuraikan ke isi katalog × jumlah set), menguncinya ke batch sebagai
`order_item`, lalu mengubah statusnya `tersedia` → `sterilisasi`. Karena unit
sudah terpasang, tahap **Packaging tidak meng-generate ulang**; unit yang sama
mengalir lewat pipeline dan **kembali `tersedia`** saat sterilisasi selesai.

Bila stok `tersedia` tidak mencukupi untuk salah satu instrumen, seluruh proses
dibatalkan (rollback) dan mengembalikan **422** — tidak ada batch yang dibuat.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| note | string | Tidak | Catatan opsional batch produksi |
| items | array | Ya | Baris produksi (min 1) |
| items[].type | string | Ya | `satuan` atau `paket` |
| items[].quantity | integer | Ya | Jumlah (min 1) |
| items[].instrument_id | integer | Ya jika `type=satuan` | ID instrumen (exists:instruments,id) |
| items[].instrument_catalog_id | integer | Ya jika `type=paket` | ID katalog/set (exists:instrument_catalogs,id) |
| items[].package_name | string | Tidak | Nama paket (untuk `type=paket`) |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Batch produksi berhasil dibuat & masuk tahap Cleaning.",
  "data": {
    "id": 12,
    "code": "PRD26070801",
    "note": null,
    "created_by": "Admin",
    "created_at": "2026-07-08T08:00:00.000000Z",
    "items": [
      {
        "id": 51,
        "instrument_stock_id": 18,
        "kode_instrumen": "KMK-001",
        "name": "Kom Kecil",
        "source": "satuan",
        "package_name": null,
        "condition_out_id": 1
      }
    ],
    "washings": [
      { "id": 9, "code": "WSH26071909", "production_code": "PRD26070801", "status": "dalam_proses" }
    ]
  }
}
```

> **Tanpa `status`, `started_*`, maupun `completed_*`.** Batch dibuat dan unit
> dikunci dalam satu aksi, jadi tidak ada keadaan antara yang perlu dicatat —
> `created_at`/`created_by` sudah mewakili waktu batch dibuat berikut pelakunya.
>
> **`items[].kode_instrumen` & `items[].name` adalah snapshot**, disalin dari
> `instrument_stocks.code` dan `instruments.name` saat unit dikunci — bukan dibaca
> lewat relasi. Perubahan data master setelahnya (rename instrumen, kode unit
> diubah, unit dihapus) tidak mengubah riwayat batch lama.

#### Error (422) — validasi input
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "items": ["The items field is required."] }
}
```

#### Error (422) — stok tidak cukup
```json
{
  "status": false,
  "message": "Stok \"Gunting Bedah\" tidak cukup: butuh 5, tersedia 2."
}
```

#### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
