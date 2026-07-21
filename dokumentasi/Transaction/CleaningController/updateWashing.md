# CleaningController@updateWashing

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** PUT
**Endpoint:** /api/master/cleaning/{order}/washing
**Auth:** Bearer Token (wajib)

Menyimpan / memperbarui catatan pencucian (Tahap 2 — Cleaning & Disinfection).

**Perilaku:**
- `washer_machine_id` mencatat mesin washer yang dipilih (lihat `washer-machines/scan`, kini lookup via id).
  Suhu & durasi yang diinput dievaluasi terhadap ambang mesin → bila di luar
  rentang, sistem menandai `alert = true` dan mengisi `alert_message`
  (notifikasi kegagalan suhu/waktu).
- `complete = true` menandai **"Selesai Cuci"** → washing berstatus `selesai` dan
  batch masuk **antrean** tahap Packaging. Record `packaging` + `packaging_item`
  **tidak** dibuat di sini; keduanya baru ditulis saat petugas menekan "Selesai &
  Cetak Label" (`POST /master/packaging/complete`). **Ditolak (422)** selama masih
  ada `alert` parameter.
- `fail = true` menandai pencucian **"Gagal"** (wajib diulang); status washing
  menjadi `gagal`, order tetap di tahap `pencucian` (mencatat event `gagal`).
  **Hanya penanda** — parameter pencucian **tidak** diproses/diperbarui dan tahap
  **tidak** diselesaikan (field parameter pada payload diabaikan; cukup kirim
  `fail` + `failure_reason`).

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `pencucian`/`pengemasan`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| washer_machine_id | integer | Ya¹ | ID mesin washer (FK `washer_machines`) — pengganti kode/barcode yang sudah dihapus |
| operator | string | Tidak | ID / nama operator |
| temperature | string | Ya¹ | Suhu pencucian (°C) |
| washed_at | date | Ya¹ | Waktu mulai pencucian |
| duration_minutes | integer | Ya¹ | Durasi pencucian (menit) |
| detergent_type | string | Ya¹ | Jenis deterjen / enzimatis |
| complete | boolean | Tidak | Tandai "Selesai Cuci" |
| completed_at | date | Tidak | Waktu selesai (default now) |
| fail | boolean | Tidak | Tandai "Gagal" (wajib diulang) |
| failure_reason | string | Tidak | Alasan gagal (default: pesan alert / "Pencucian gagal.") |

> ¹ **Wajib hanya pada aksi Simpan biasa** (tanpa `complete`/`fail`). Saat `complete=true` (Selesai Cuci) atau `fail=true` (Tandai Gagal), field parameter di atas bersifat opsional.

### Response

#### Success (200) — tersimpan / selesai
```json
{
  "status": true,
  "message": "Catatan pencucian berhasil disimpan.",
  "data": {
    "id": 12,
    "status": "pengemasan",
    "washing": {
      "washer_machine": { "id": 1, "name": "Washer Disinfector 1" },
      "operator": "OP-7",
      "temperature": "70",
      "duration_minutes": 20,
      "detergent_type": "Enzimatik",
      "status": "selesai",
      "alert": false,
      "alert_message": null,
      "failure_reason": null,
      "completed_at": "2026-06-28T08:45:00.000000Z"
    }
  }
}
```

#### Success (200) — ditandai gagal
```json
{
  "status": true,
  "message": "Pencucian ditandai gagal dan harus diulang.",
  "data": {
    "id": 12,
    "status": "pencucian",
    "washing": { "status": "gagal", "failure_reason": "Indikator kotor masih tersisa." }
  }
}
```

#### Error (422) — parameter di luar ambang mesin saat mencoba menyelesaikan
```json
{
  "status": false,
  "message": "Parameter pencucian di luar ambang mesin: Suhu 45°C di bawah minimum mesin (55°C). Periksa ulang atau tandai gagal."
}
```

#### Error (422) — bukan tahap cleaning
```json
{ "status": false, "message": "Order ini tidak sedang dalam tahap cleaning." }
```
