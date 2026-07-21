# PackagingController — complete

**Controller:** App\Http\Controllers\Transaction\PackagingController
**Base URL:** /api/master

---

> **Dua jalur penyelesaian.** Record `packaging` + `packaging_item` tidak dibuat
> saat cleaning selesai maupun saat modal inspeksi dibuka — keduanya baru ditulis
> ketika petugas menekan **"Selesai & Cetak Label"**.
>
> | Kondisi batch | Endpoint |
> |---|---|
> | Antrean (`started: false`, belum ada record) | `POST /api/master/packaging/complete` dengan `washing_code` — **membuat** record lalu menyelesaikannya |
> | Sudah ada record (mis. RPK pengemasan ulang) | `POST /api/master/packaging/{packaging}/complete` — hanya menyelesaikan |
>
> Field data pengemasan (`chemical_indicator`, `packaging_type_id`, dst) identik
> di kedua jalur.

## 1. complete (Selesai Inspection & Packaging — record sudah ada)

**Method:** POST
**Endpoint:** /api/master/packaging/{packaging}/complete
**Auth:** Bearer Token (wajib)

Menyelesaikan tahap Inspection & Packaging sebuah batch yang **recordnya sudah
ada** — dipakai untuk batch pengemasan ulang (RPK) yang lahir dari unit gagal
steril. Petugas telah
memverifikasi komponen set (checklist scan barcode dilakukan di sisi klien —
unit sudah terkunci sejak Produksi) lalu **wajib** mencatat nomor lot/batch
indikator kimia internal yang dimasukkan ke kemasan serta memilih **jenis
kemasan** — pilihan inilah yang menentukan masa simpan steril, sehingga
menentukan tgl kedaluwarsa batch (ditetapkan di tahap ini, sebelum sterilisasi).

Daftar pilihan jenis kemasan diambil dari `GET /master/packaging-types/options`
(master: `Master\PackagingTypeController`).

Efek:
- Record `packaging` → status `selesai`, menyimpan `chemical_indicator`,
  `packaging_type_id`, `operator` (= user login bila kosong), `packaged_at`,
  `completed_by/at`.
- `expiry_date` **dihitung server** = `packaged_at` + `shelf_life_days` jenis
  kemasan terpilih, lalu disimpan sebagai snapshot — mengubah masa simpan sebuah
  jenis kemasan di master kemudian hari tidak menggeser tanggal batch yang sudah
  dikemas.
- Batch menjadi kandidat tahap Sterilisasi (muncul di `GET /master/sterilization-pipeline`).
  `expiry_date` diwariskan ke batch sterilisasi yang dibuat dari tray ini bila
  operator tidak mengisi expiry sendiri saat memulai sterilisasi.
- Mengembalikan **data label sterilisasi** untuk dicetak (satu label per unit):
  nama set, nomor batch, indikator kimia, ID petugas, tgl kemas/sterilisasi, dan
  tgl kedaluwarsa sesuai `expiry_date` yang dikirim. Batch lama yang dikemas
  sebelum field ini ada tetap memakai default (tgl kemas + 7 hari).

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| packaging | integer | ID record packaging (PKG) |

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| chemical_indicator | string | Ya | Nomor lot/batch indikator kimia internal |
| packaging_type_id | integer | Ya | ID master jenis kemasan (dari `/master/packaging-types/options`). Harus belum dihapus. Menentukan masa simpan steril → `expiry_date` |
| operator | string | Tidak | Petugas pengemas (default: user login) |
| packaged_at | datetime | Tidak | Waktu pengemasan (default: sekarang) |
| note | string | Tidak | Catatan opsional |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Packaging selesai — batch siap masuk tahap sterilisasi.",
  "data": {
    "id": 1,
    "code": "PKG26071901",
    "code_transaction": "PRD20260702001",
    "status": "pengemasan",
    "chemical_indicator": "CI-LOT-99",
    "packaging_type": "pouch",
    "packaging_type_label": "Pouch Plastik",
    "packaged_at": "2026-07-02T08:00:00+00:00",
    "expiry_date": "2026-08-01",
    "units_count": 6,
    "label": {
      "batch": "PRD20260702001",
      "packaging_code": "PKG26071901",
      "set_name": "SET PARTUS",
      "packer": "Admin",
      "packaging_type": "Pouch Plastik",
      "packaged_at": "2026-07-02T08:00:00+00:00",
      "expiry_date": "2026-08-01",
      "chemical_indicator": "CI-LOT-99",
      "items": [
        { "id": 27, "instrument_name": "Gunting Epis", "unit_code": "GNE-001", "source": "paket", "package_name": "SET PARTUS" }
      ]
    }
  }
}
```

#### Error (422) — indikator kimia / jenis kemasan wajib
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "chemical_indicator": ["The chemical indicator field is required."],
    "packaging_type": ["The selected packaging type is invalid."]
  }
}
```

#### Error (422) — sudah selesai
```json
{
  "status": false,
  "message": "Batch packaging ini sudah diselesaikan."
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

## 2. completeQueued (Selesai & Cetak Label — batch antrean)

**Method:** POST  
**Endpoint:** /api/master/packaging/complete  
**Auth:** Bearer Token (wajib)

Untuk batch yang masih **antrean** (`started: false` di `GET /master/packaging`):
record `packaging` + `packaging_item` dibuat di sini, lalu langsung ditandai
selesai — semuanya dalam satu transaksi. Isi `packaging_item` = cermin seluruh
`production_item` batch tersebut.

Dengan alur ini tidak ada baris packaging yang menumpuk bila petugas membuka
modal inspeksi lalu membatalkannya.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| washing_code | string | Ya | Kode batch cleaning yang sudah selesai (mis. `WSH26071903`) |
| chemical_indicator | string | Ya | No. lot indikator kimia internal |
| packaging_type_id | integer | Ya | Jenis kemasan — masa simpannya menentukan `expiry_date` |
| operator | string | Tidak | Petugas pengemas (default: user login) |
| packaged_at | date | Tidak | Waktu dikemas (default: sekarang) |
| note | string | Tidak | Catatan |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Packaging selesai — batch siap masuk tahap sterilisasi.",
  "data": {
    "id": 5,
    "code": "PKG26071903",
    "round": 1,
    "started": true,
    "stage_status": "selesai",
    "label": { "batch": "PRD26071903", "items": [] },
    "...": null
  }
}
```

#### Error (404)
```json
{ "status": false, "message": "Batch cleaning tidak ditemukan." }
```

#### Error (422)
```json
{ "status": false, "message": "Batch ini sudah punya data pengemasan. Muat ulang daftarnya." }
```
