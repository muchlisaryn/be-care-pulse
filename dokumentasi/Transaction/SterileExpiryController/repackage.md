# SterileExpiryController — repackage

**Controller:** App\Http\Controllers\Transaction\SterileExpiryController
**Base URL:** /api/master/sterile-expiry

---

## 4. repackage (Packaging Ulang)

**Method:** POST
**Endpoint:** /api/master/sterile-expiry/{sterilization}/repackage
**Auth:** Bearer Token (wajib)

Tarik label **kedaluwarsa** dari rak lalu buka **ronde pengemasan baru** (record
`RPK`) berisi unit-unitnya, sehingga barangnya muncul lagi di tab Packaging dengan
**kode pengemasan & nomor label baru**.

Mekanismenya sama persis dengan jalur unit **gagal steril** — keduanya memakai
`App\Traits\ReprocessesPackaging::openReprocessRound()`, supaya bentuk datanya tidak
pernah menyimpang antara dua pemicu.

### Data tidak hilang

Baris `instrument_storages` **tidak dihapus**, hanya di-void: `disabled = true` +
`disabled_at` diisi (`status` → `keluar`, `removed_at` diisi). Riwayat rak, batch
steril, dan nomor label lamanya tetap terbaca.

Efek void itu berlapis dan semuanya disengaja:

| Dampak | Ditegakkan oleh |
|---|---|
| Hilang dari Gudang Steril & daftar Kedaluwarsa | `InstrumentStorage::sterilePool()` / `stillInRack()` |
| **Tidak bisa dipinjam** — hilang dari kandidat distribusi, angka siap-order, dan `available_sterile_sets` | `sterilePool()` (syarat `disabled_at` NULL) |
| Tidak terhitung sebagai stok bebas di Master | `InstrumentStock::scopeAvailableStock()` |
| Badge tahap unit → **Pengemasan** | `InstrumentStock::computeStages()` |
| Status unit → `sterilisasi` | `InstrumentStock::transitionMany()` |

Pada PKG lama: `packaging_item` unit yang ditarik di-void, dan PKG-nya sendiri hanya
di-void bila **seluruh** isinya ikut ditarik — penarikan sebagian menyisakan record
lama berisi unit yang tidak ditarik agar jejaknya tetap terlihat di History.

### Pilihan diperluas ke satu label utuh

Server **memperluas** `storage_ids` ke seluruh isi label yang tersentuh. Begitu satu
unit sebuah set ditarik, bungkusnya sudah dibuka, jadi sisa isinya tidak boleh
ditinggal di rak sebagai set tak lengkap yang tak bisa didistribusikan dan tak bisa
ditarik. Karena itu `units` pada response bisa **lebih besar** daripada jumlah
`storage_ids` yang dikirim.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| storage_ids | array\<integer\> | Ya | Id baris `instrument_storages` yang dipilih (minimal 1). Id di luar batch ini diabaikan. |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "5 unit (1 label) ditarik dari rak & masuk antrean Packaging: RPK26082301.",
  "data": {
    "labels": 1,
    "units": 5,
    "packagings": ["RPK26082301"]
  }
}
```

#### Error (422) — batch lama tanpa sterilisasi
```json
{
  "status": false,
  "message": "Baris gudang ini tidak terhubung ke batch sterilisasi mana pun (data lama), sehingga kemasan asalnya tidak bisa dilacak untuk dikemas ulang."
}
```

#### Error (422) — sudah diproses petugas lain
```json
{
  "status": false,
  "message": "Tidak ada unit yang bisa dikemas ulang — kemungkinan sudah diproses petugas lain. Muat ulang daftarnya."
}
```

#### Error (422) — ada yang belum kedaluwarsa
```json
{
  "status": false,
  "message": "Hanya unit yang SUDAH kedaluwarsa yang bisa dikemas ulang; 2 unit terpilih masih berlaku."
}
```

#### Error (422) — kemasan asal tidak terlacak
```json
{
  "status": false,
  "message": "Kemasan asal 3 unit tidak ditemukan pada batch STR26071901, jadi ronde pengemasan barunya tidak bisa dibuat. Tidak ada perubahan yang disimpan."
}
```

> Seluruh aksi berjalan dalam **satu transaksi** dan barisnya dikunci
> (`lockForUpdate`), jadi dua petugas yang menekan tombol bersamaan tidak bisa
> sama-sama membuka ronde untuk unit yang sama. Bila ada satu unit saja yang kemasan
> asalnya tidak terlacak, **seluruh** transaksi dibatalkan — lebih baik tidak ada
> perubahan daripada meninggalkan unit yang baris raknya sudah di-void tapi tidak
> masuk ronde pengemasan mana pun.

#### Error (404)
```json
{ "status": false, "message": "Batch sterilisasi tidak ditemukan." }
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
