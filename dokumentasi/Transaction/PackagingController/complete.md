# PackagingController — complete

**Controller:** App\Http\Controllers\Transaction\PackagingController
**Base URL:** /api/master

---

## 1. complete (Selesai Inspection & Packaging)

**Method:** POST
**Endpoint:** /api/master/packaging/{packaging}/complete
**Auth:** Bearer Token (wajib)

Menyelesaikan tahap Inspection & Packaging sebuah batch PKG. Petugas telah
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
    "code": "PKG-001",
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
      "packaging_code": "PKG-001",
      "set_name": "SET PARTUS",
      "packer": "Admin",
      "packaging_type": "Pouch Plastik",
      "packaged_at": "2026-07-02T08:00:00+00:00",
      "expiry_date": "2026-08-01",
      "chemical_indicator": "CI-LOT-99",
      "items": [
        { "instrument_name": "Gunting Epis", "unit_code": "GNE-001", "source": "paket", "package_name": "SET PARTUS" }
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
