# PackagingController — label

**Controller:** App\Http\Controllers\Transaction\PackagingController
**Base URL:** /api/master

---

## 1. label (Lihat / Cetak Ulang Label Sterilisasi)

**Method:** GET
**Endpoint:** /api/master/packaging/{packaging}/label
**Auth:** Bearer Token (wajib)

Mengambil ulang **data Label Barcode Sterilisasi** sebuah batch packaging agar
bisa dilihat / dicetak ulang kapan saja setelah tahap Inspection & Packaging
selesai. Data label dihitung dari record `packaging` yang tersimpan (nama set,
nomor produksi/batch, petugas pengemas, tgl sterilisasi, indikator kimia, dan
tgl kedaluwarsa otomatis), jadi tidak hilang meski modal label sebelumnya sudah
ditutup. Payload identik dengan field `label` pada respons `complete`.

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| packaging | integer | ID record packaging (PKG) |

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Label sterilisasi berhasil diambil.",
  "data": {
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

#### Error (404) — batch tidak ditemukan
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
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
