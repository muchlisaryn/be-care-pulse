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
indikator kimia internal yang dimasukkan ke kemasan.

Efek:
- Record `packaging` → status `selesai`, menyimpan `chemical_indicator`,
  `operator` (= user login bila kosong), `packaged_at`, `completed_by/at`.
- Batch menjadi kandidat tahap Sterilisasi (muncul di `GET /master/sterilization-pipeline`).
- Mengembalikan **data label sterilisasi** untuk dicetak (satu label per unit):
  nama set, nomor batch, indikator kimia, ID petugas, tgl kemas/sterilisasi, dan
  **tgl kedaluwarsa otomatis** (= tgl kemas + masa simpan default 7 hari).

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
    "units_count": 6,
    "label": {
      "batch": "PRD20260702001",
      "packaging_code": "PKG-001",
      "set_name": "SET PARTUS",
      "packer": "Admin",
      "packaged_at": "2026-07-02T08:00:00+00:00",
      "expiry_date": "2026-07-09",
      "chemical_indicator": "CI-LOT-99",
      "items": [
        { "instrument_name": "Gunting Epis", "unit_code": "GNE-001", "source": "paket", "package_name": "SET PARTUS" }
      ]
    }
  }
}
```

#### Error (422) — indikator kimia wajib
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "chemical_indicator": ["The chemical indicator field is required."] }
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
