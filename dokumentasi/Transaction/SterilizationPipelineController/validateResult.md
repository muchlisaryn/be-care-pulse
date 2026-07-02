# SterilizationPipelineController — validateResult

**Controller:** App\Http\Controllers\Transaction\SterilizationPipelineController
**Base URL:** /api/master

---

## 1. validateResult (Validasi Hasil Batch Sterilisasi)

**Method:** POST
**Endpoint:** /api/master/sterilization-pipeline/{sterilization}/validate
**Auth:** Bearer Token (wajib)

Memvalidasi hasil sebuah batch sterilisasi (STR berstatus `diproses`) pada pipeline produksi.

- `result = selesai` (Steril): batch → `selesai`, `expiry_date` otomatis bila kosong
  (= tgl sterilisasi + masa simpan default 7 hari), seluruh unit → `tersedia` (steril).
- `result = gagal`: batch → `gagal`, unit dibebaskan, dan **semua PKG anggota** dilepas
  (`sterilization_id` di-null-kan) sehingga kembali muncul sebagai siap-steril.

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| sterilization | integer | ID batch STR (status `diproses`) |

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| result | string | Ya | `selesai` (Steril) atau `gagal` |
| chemical_indicator | string | Tidak | Hasil indikator kimia |
| biological_indicator | string | Tidak | Hasil indikator biologis |
| expiry_date | date | Tidak | Kedaluwarsa steril (override otomatis) |
| note | string | Tidak | Catatan |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Sterilisasi tervalidasi: alat steril & siap rilis.",
  "data": { "sterilization_code": "STR-007" }
}
```

#### Error (422)
```json
{ "status": false, "message": "Batch ini tidak sedang diproses." }
```

#### Error (500)
```json
{ "status": false, "message": "pesan error asli dari exception", "code": 0, "file": "...", "line": 42 }
```
