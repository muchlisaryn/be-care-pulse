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

### Unit yang dipinjam atau masih di rak DIKECUALIKAN

Kandidat produksi disaring dua kali, dan keduanya wajib:

| Saringan | Membuang | Kenapa status saja tidak cukup |
|---|---|---|
| `instrument_stocks.status = tersedia` | Unit yang sedang **dipinjam**, sedang di tengah siklus produksi lain, atau baru dikembalikan | — |
| `InstrumentStorage::heldInRackStockIds()` | Unit yang **fisiknya masih di rak** gudang steril: `deleted_by` NULL + `removed_at` NULL | Unit di rak statusnya TETAP `tersedia` sampai benar-benar didistribusikan — termasuk yang sudah direservasi sebuah order |

Kalau unit rak ikut terambil, baris gudangnya ditutup diam-diam oleh
`closeStorageForReprocessed` — petugas hanya memilih **jenis + jumlah**, bukan unit
fisiknya — dan stoknya **lenyap dari halaman Gudang Steril tanpa pernah dipinjam**.
Untuk baris yang sudah direservasi order, ordernya ikut kehilangan barang yang
sudah dijanjikan ke pemesan.

`heldInRackStockIds()` sengaja **bukan** turunan `sterilePool()`: scope itu
mensyaratkan `order_id` NULL, sehingga baris yang direservasi order justru lolos
dari saringan — padahal barangnya jelas masih menempati rak.

**Pengecualiannya: unit yang sudah KEDALUWARSA tetap boleh diproduksi ulang.**
Justru itulah yang wajib diproses. Kalau ikut dilindungi, unitnya terjebak
permanen — distribusi menolaknya lewat `blockedPackagingBarcodes()`, sedangkan
halaman Kedaluwarsa (`SterileExpiryController`) hanya punya `index` & `summary`:
memantau, tanpa aksi menarik unit dari rak. Produksi adalah satu-satunya jalan
keluar unit kedaluwarsa dari gudang. Baris raknya ditutup
`closeStorageForReprocessed` (`status` → `keluar`, `removed_at` diisi) saat unit
ditarik.

> **Tidak ada constraint database yang menjaga aturan ini** — index unik
> `active_stock_id` sempat dirancang lalu dibatalkan, karena memasangnya pada
> data yang sudah berjalan berisiko gagal di tengah jalan. Aturannya sepenuhnya
> hidup di kode, jadi pemeriksaan di `StorageController` (unit harus berstatus
> `tersedia` sebelum masuk rak) bersifat baca-lalu-tulis: dua petugas yang
> menyimpan batch yang sama pada detik yang sama secara teori masih bisa
> lolos berdua. `UniqueConstraintViolationException` tetap ditangkap di kedua
> jalur penyimpanan sebagai jaring pengaman untuk database yang memasang
> constraint-nya sendiri.

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

**Angka `tersedia` di pesan ini SELALU lebih kecil** daripada jumlah bertanda
"Tersedia" di Master › Katalog Instrumen, karena unit yang masih menempati rak
ikut dibuang (lihat bagian "Unit yang dipinjam atau masih di rak dikecualikan").
Supaya selisih itu bisa dijelaskan petugas, pesannya menyebutkan berapa yang
tertahan:

```json
{
  "status": false,
  "message": "Stok \"Gunting Bedah\" tidak cukup: butuh 5, tersedia 2. 3 unit lain masih tersimpan di rak gudang steril dan tidak bisa diproduksi ulang selama masih berlaku."
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
