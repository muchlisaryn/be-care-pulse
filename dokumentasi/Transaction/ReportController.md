# ReportController

**Controller:** App\Http\Controllers\Transaction\ReportController  
**Base URL:** /api/master/reports

---

## 1. cssdPerItem

Laporan CSSD Per Alat — satu baris per **LABEL KEMASAN** (`barcode_no`) pada tiap batch
sterilisasi. Satu label = satu bungkus fisik, jadi seluruh unit yang dikemas bersama
lebur menjadi satu baris dengan rincian tiap asetnya di `units`.

Sumber label: `sterilization_items.packaging_barcode` — nomor label yang unit itu
BENAR-BENAR bawa saat batch tersebut disterilkan, sehingga laporan lama tidak bergeser
saat unitnya dikemas ulang di siklus berikutnya. Baris lama yang kolomnya masih kosong
jatuh ke label terbaru unit itu (`packaging_item.barcode_no`, label void diabaikan).
Unit yang belum punya label sama sekali TIDAK pernah digabung — kalau digabung, dua
bungkus berbeda akan tampak sebagai satu baris.

Nama alat/paket diambil dari SNAPSHOT `production_item` (bukan master) supaya laporan
lama tidak berubah saat instrumen di-rename; `order_item` hanya cadangan nama paket.
BMHP tidak termasuk (hanya didistribusi, tidak disterilkan).

Pengelompokan dilakukan di server lalu dipaginasi per-grup (agar satu bungkus tidak
terpotong antar halaman).

> Asal **paket / satuan** sudah tidak lagi menentukan pengelompokan maupun dikirim
> sebagai field: label kemasan adalah yang benar-benar dipegang petugas, dan satu order
> paket bisa terbagi ke beberapa bungkus (atau sebaliknya). Field `type`, `status`
> (tingkat batch), dan `condition` juga sudah TIDAK dikirim lagi.

**Method:** GET  
**Endpoint:** /api/master/reports/cssd-per-item  
**Auth:** Bearer Token (wajib)

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada nama instrumen atau kode unit (mis. `GNT-001`) |
| status | string | Tidak | Filter status batch: `diproses` / `selesai` / `gagal` |
| method | string | Tidak | Filter metode steril: `uap` / `eo` / `plasma` / `panas_kering` |
| machine | string | Tidak | Filter mesin (cocok persis dengan `sterilizations.machine`). Pilihannya diambil dari [cssdMachines](#2-cssdmachines) |
| result | string | Tidak | Filter hasil unit: `berhasil` / `gagal`. Dibaca dari `sterilization_items.disabled` (unit gagal di-void saat validasi batch) |
| date_from | date | Tidak | Tanggal sterilisasi (`sterilized_at`) ≥ tanggal ini |
| date_to | date | Tidak | Tanggal sterilisasi (`sterilized_at`) ≤ tanggal ini |
| page | integer | Tidak | Halaman pagination |
| per_page | integer | Tidak | Jumlah per halaman (default 20, maks 2000 — dipakai saat export) |

### Response

Setiap baris pada `data.data` adalah satu **label kemasan**:

| Field | Type | Keterangan |
|-------|------|------------|
| key | string | Kunci baris unik (`label\|batchId\|barcode`, atau `nolabel\|itemId` bila unit belum berlabel) |
| barcode_no | string\|null | Nomor label kemasan; `null` bila unitnya belum pernah dikemas |
| name | string\|null | Nama paket bila unitnya bagian dari sebuah paket, selain itu nama instrumennya |
| unit_code | string\|null | Kode unit — hanya terisi bila label ini berisi SATU unit; `null` untuk baris gabungan |
| batch_code | string\|null | Kode batch sterilisasi (STR-NNN) |
| machine | string\|null | Mesin sterilisasi, mis. `AUTOCLAVE 285 LTR` |
| method | string\|null | Metode: `uap` / `eo` / `plasma` / `panas_kering` |
| cycle_number | string\|null | No. siklus batch, mis. `C003` |
| temperature | string\|null | Suhu dalam °C (desimal, mis. `"134.00"`) |
| duration_minutes | integer\|null | Durasi batch dalam menit |
| operator | string\|null | Petugas yang menjalankan batch |
| sterilized_at | datetime\|null | Waktu steril (tanggal + jam) |
| chemical_indicator | string\|null | Hasil indikator kimia (mis. `Lulus`); `null` bila batch belum divalidasi |
| bio_indicator_control | string\|null | Indikator biologi **pembanding** (kontrol): `Positif` / `Negatif` |
| bio_indicator_test | string\|null | Indikator biologi **uji**: `Positif` / `Negatif` |
| expiry_date | date\|null | Batas kedaluwarsa steril |
| qty | integer | Jumlah aset di dalam label ini |
| failed | boolean | `true` bila ADA unit di bungkus ini yang gagal steril (sumber: `sterilization_items.disabled`). Satu bungkus bisa berisi campuran hasil, jadi bungkus bermasalah tidak pernah terlihat aman. Frontend menandai barisnya MERAH tapi tetap menampilkannya |
| units | array | Rincian tiap aset: `id`, `name`, `unit_code`, `result`, `failed` |

> Unit yang gagal steril **tidak** disembunyikan dari laporan — laporan ini juga dipakai
> menelusuri kegagalan. Catatan saat `?result=` dipakai: penyaringan bekerja di tingkat
> UNIT, jadi bungkus dengan hasil campuran tetap muncul di kedua nilai filter, dengan
> `qty` & `units` hanya berisi unit yang cocok.

#### Success (200)
```json
{
  "status": true,
  "message": "Laporan CSSD per alat berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "key": "label|3|PKG260805011",
        "barcode_no": "PKG260805011",
        "name": "SET PARTUS",
        "unit_code": null,
        "batch_code": "STR-001",
        "machine": "AUTOCLAVE 285 LTR",
        "method": "uap",
        "cycle_number": "C003",
        "temperature": "134.00",
        "duration_minutes": 30,
        "operator": "-",
        "sterilized_at": "2026-08-05T13:00:00.000000Z",
        "chemical_indicator": "Lulus",
        "bio_indicator_control": "Positif",
        "bio_indicator_test": "Negatif",
        "expiry_date": "2026-09-04T00:00:00.000000Z",
        "failed": true,
        "qty": 2,
        "units": [
          { "id": 10, "name": "Gunting Epis", "unit_code": "GNE-001", "result": "berhasil", "failed": false },
          { "id": 11, "name": "Kom Kecil", "unit_code": "KMK-001", "result": "gagal", "failed": true }
        ]
      },
      {
        "key": "label|3|PKG260805012",
        "barcode_no": "PKG260805012",
        "name": "Bengkok",
        "unit_code": "BKK-003",
        "batch_code": "STR-001",
        "machine": "AUTOCLAVE 285 LTR",
        "method": "uap",
        "cycle_number": "C003",
        "temperature": "134.00",
        "duration_minutes": 30,
        "operator": "-",
        "sterilized_at": "2026-08-05T13:00:00.000000Z",
        "chemical_indicator": "Lulus",
        "bio_indicator_control": "Positif",
        "bio_indicator_test": "Negatif",
        "expiry_date": "2026-09-04T00:00:00.000000Z",
        "failed": false,
        "qty": 1,
        "units": [
          { "id": 22, "name": "Bengkok", "unit_code": "BKK-003", "result": "berhasil", "failed": false }
        ]
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 2
  }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "status": ["The selected status is invalid."] }
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

---


## 2. cssdMachines

Pilihan filter **Mesin** untuk [cssdPerItem](#1-cssdperitem): daftar mesin yang
benar-benar pernah dipakai pada batch sterilisasi (distinct `sterilizations.machine`,
urut nama).

Sengaja TIDAK diambil dari master `sterilizer-machines`: kolom `sterilizations.machine`
menyimpan NAMA mesin sebagai teks (snapshot saat batch dijalankan), jadi mesin yang
sudah dinonaktifkan atau di-rename di master tidak akan cocok — padahal batch lamanya
masih ada di laporan. Karena diambil dari datanya sendiri, pilihannya selalu sama dengan
isi laporan.

**Method:** GET  
**Endpoint:** /api/master/reports/cssd-machines  
**Auth:** Bearer Token (wajib)

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
Tidak ada.

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Daftar mesin sterilisasi berhasil diambil.",
  "data": ["AUTOCLAVE 100 LTR", "AUTOCLAVE 285 LTR"]
}
```

#### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
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
