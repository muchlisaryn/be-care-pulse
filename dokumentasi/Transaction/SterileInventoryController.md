# SterileInventoryController

**Controller:** App\Http\Controllers\Transaction\SterileInventoryController
**Base URL:** /api/master/sterile-inventory

Endpoint **khusus tab "Inventaris"** halaman Gudang Steril
(`/cssd/storage-steril?tab=inventaris`). Sengaja berdiri sendiri, tidak menumpang
`StorageController@inventory` maupun endpoint lain: aturan tampilan tab ini berbeda dari
mana pun, jadi perubahan di sini tidak boleh menggeser angka atau isi halaman lain.

## Aturan yang membedakan tab ini

Baris yang **sudah kedaluwarsa** atau **tersimpan tanpa tanggal kedaluwarsa** TETAP
ditampilkan — petugas justru perlu tahu barangnya ada di rak tapi harus diproses ulang.
Baris itu hanya **ditandai tidak bisa dipesan** lewat `can_distribute` / `blocked_reason`.

Kebalikannya angka siap-order di halaman Order Instrumen
(`InstrumentController@index.available_sterile_count`,
`InstrumentCatalogController@index.available_sterile_sets`): baris seperti itu **tidak
dihitung sama sekali** di sana.

Yang tetap dibagi dengan tempat lain hanyalah **definisi barisnya** —
`InstrumentStorage::sterilePool()` (`deleted_by` NULL + `status = 'tersimpan'` +
`order_id` NULL) dan `InstrumentStorage::blockedPackagingBarcodes()` — karena justru itu
yang tidak boleh berbeda. Kalau daftar ini berangkat dari baris yang lain, penandanya
langsung berbohong.

---

## 1. index

**Method:** GET
**Endpoint:** `/api/master/sterile-inventory`
**Auth:** Bearer Token (wajib)

Isi gudang steril + lokasi rak + status kedaluwarsa. Diurutkan dari yang paling cepat
kedaluwarsa; baris tanpa tanggal ditaruh paling akhir. Paginasi 20/halaman (frontend
memuatnya bertahap saat di-scroll).

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari di kode rak, kode/nama unit, nama & kode snapshot produksi, dan nomor label kemasan |
| days | integer | Tidak | Ambang early-warning (default 7) — dasar `alert` |
| page | integer | Tidak | Halaman |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Inventaris gudang steril berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 3,
        "rack_code": "RAK A1",
        "stored_at": "2026-08-02T11:22:12.000000Z",
        "expiry_date": "2026-08-03T00:00:00.000000Z",
        "days_to_expiry": -9,
        "alert": true,
        "expired": true,
        "can_distribute": false,
        "blocked_reason": "Kedaluwarsa",
        "source": "satuan",
        "package_name": null,
        "barcode_no": "PKG260802015",
        "production_code": "PRD26080201",
        "unit": { "id": 3, "code": "GNT-003", "instrument": "Gunting", "image_url": "/uploads/..." },
        "batch": "STR-001"
      }
    ],
    "per_page": 20,
    "total": 6,
    "last_page": 1
  }
}
```

| `blocked_reason` | Kapan |
|---|---|
| `Tanpa tanggal kedaluwarsa` | `expiry_date` NULL — sterilitasnya tidak bisa dijamin |
| `Kedaluwarsa` | `expiry_date` sudah lewat hari ini |
| `Sebungkus dengan unit kedaluwarsa` | ada unit lain di label kemasan yang sama yang kedaluwarsa/tanpa tanggal — sterilitas melekat pada bungkus, jadi seluruh isinya ikut gugur |

Urutan pemeriksaannya sama dengan `OrderController::distributionCandidates()`, jadi
penanda di layar tidak bisa berbeda dari kenyataan saat tombol Distribusikan ditekan.

Field `order` tidak dikirim: scope `sterilePool()` hanya memuat baris yang belum diklaim
order manapun.

---

## 2. summary

**Method:** GET
**Endpoint:** `/api/master/sterile-inventory/summary`
**Auth:** Bearer Token (wajib)

Kartu statistik tab ini. Dihitung dari SELURUH pool (bukan hanya halaman yang sudah
dimuat), memakai baris yang sama persis dengan `index` — kalau tidak, angkanya tidak akan
cocok dengan daftarnya.

**Aturan hitung:** paket per **SET** (satu bungkus/label = 1), satuan per unit — paket
berisi 5 instrumen tetap bernilai 1.

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| days | integer | Tidak | Ambang early-warning (default 7) — dasar `alert` |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Ringkasan gudang steril berhasil diambil.",
  "data": { "total": 6, "alert": 1, "expired": 4, "no_expiry": 0 }
}
```

| Field | Isi |
|---|---|
| `total` | Seluruh isi pool steril |
| `alert` | Masuk H-`days` menuju kedaluwarsa, belum lewat |
| `expired` | Sudah lewat tanggal kedaluwarsa |
| `no_expiry` | Tersimpan tanpa tanggal kedaluwarsa — tampil di daftar, tapi tidak bisa dipesan |

### Error (401)
```json
{ "status": false, "message": "Unauthenticated. Silakan login terlebih dahulu." }
```
