# SterilizationPipelineController — validateResult

**Controller:** App\Http\Controllers\Transaction\SterilizationPipelineController
**Base URL:** /api/master

---

## 1. validateResult (Validasi Hasil Batch Sterilisasi)

**Method:** POST
**Endpoint:** /api/master/sterilization-pipeline/{sterilization}/validate
**Auth:** Bearer Token (wajib)

Memvalidasi hasil sebuah batch sterilisasi (STR berstatus `diproses`) **per unit**:
operator mencentang tiap alat berhasil / gagal steril. Body mengirim
`failed_stock_ids` = daftar `instrument_stock_id` yang **gagal**; unit lain dianggap berhasil.

- **Unit berhasil** → item `result = berhasil`, unit → `tersedia` (steril & siap rilis).
- **Unit gagal** → item `result = gagal`, unit tetap belum steril (`sterilisasi`) dan
  **muncul kembali sebagai unit re-proses lepas** di `GET /master/sterilization-pipeline`
  (`reprocess: true`) — tidak ikut batch yang berhasil.
- **Status batch:** `selesai` bila ada ≥1 unit berhasil, selain itu `gagal`. `expiry_date`
  diisi otomatis bila kosong (= tgl sterilisasi + masa simpan default 7 hari) saat ada unit berhasil.

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
| failed_stock_ids | array<int> | Tidak | `instrument_stock_id` unit yang **gagal** steril. Kosong/absen = semua unit berhasil. |
| chemical_indicator | string | Ya | Hasil indikator kimia (level batch) — wajib diisi |
| biological_indicator | string | Tidak | (Legacy) hasil indikator biologis tunggal |
| bio_indicator_control | string | Tidak | Indikator biologi **pembanding** — `Negatif` / `Positif` |
| bio_indicator_test | string | Tidak | Indikator biologi **uji** — `Negatif` / `Positif` |
| note | string | Tidak | Catatan |

> Catatan: `expiry_date` **tidak** lagi diinput dari UI — otomatis (lihat `batch`).

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Validasi tersimpan: 5 unit steril, 1 unit gagal → antre re-proses.",
  "data": { "sterilization_code": "STR-007", "passed": 5, "failed": 1 }
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
