# PointClinicalPathwayController

**Controller:** App\Http\Controllers\ClinicalPathway\PointClinicalPathwayController
**Base URL:** /api/clinical-pathway

Poin (& sub-poin) formulir untuk satu Template Clinical Pathway. Setiap poin
menempel pada satu **kategori** (`categori_clinical_pathway`). Penomoran mengikuti
kategori: kategori `urutan=1` → poin `1.1` → sub-poin `1.1.1` (dihitung di frontend
dari hierarki + `urutan`). Field poin: `label`, `pengisi` (dokter/perawat/farmasi/
ahli_gizi/penunjang), dan `hari_wajib` (array hari ke berapa poin wajib diceklis, 1..maksimal_hari).

> **Pengisi sub-poin selalu mengikuti poin induknya.** Saat `parent_id` diisi, nilai
> `pengisi` pada body diabaikan dan diambil dari induk. Mengubah `pengisi` sebuah poin
> otomatis mem-propagate ke seluruh sub-poin (keturunan)-nya.

---

## 1. index

**Method:** GET
**Endpoint:** /api/clinical-pathway/templates/{template}/points
**Auth:** Bearer Token (wajib)

Seluruh poin (flat, terurut `urutan`) milik template. Frontend menyusun pohonnya
berdasarkan `parent_id`.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data poin formulir berhasil diambil.",
  "data": [
    {
      "id": 1, "template_id": 3, "categori_id": 1, "parent_id": null,
      "label": "Pemeriksaan tanda vital", "pengisi": "perawat",
      "hari_wajib": [1, 2, 3], "urutan": 1,
      "categori": { "id": 1, "urutan": 1, "label": "Anamnesis" }
    },
    {
      "id": 2, "template_id": 3, "categori_id": 1, "parent_id": 1,
      "label": "Tekanan darah", "pengisi": "perawat",
      "hari_wajib": [1], "urutan": 1, "categori": { "id": 1, "urutan": 1, "label": "Anamnesis" }
    }
  ]
}
```

---

## 2. store

**Method:** POST
**Endpoint:** /api/clinical-pathway/templates/{template}/points
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| categori_id | integer | Ya | ID kategori (`categori_clinical_pathway`) |
| parent_id | integer | Tidak | Poin induk (untuk sub-poin); harus poin di template yang sama |
| label | string | Ya | Label poin |
| pengisi | string | Ya | Salah satu: `dokter`, `perawat`, `farmasi`, `ahli_gizi`, `penunjang` |
| hari_wajib | int[] | Tidak | Hari wajib ceklis, tiap nilai 1..`maksimal_hari` template |

`urutan` di-set otomatis (urut terakhir di antara saudaranya).

### Response — Success (201)
```json
{
  "status": true,
  "message": "Poin berhasil ditambahkan.",
  "data": { "id": 1, "template_id": 3, "categori_id": 1, "parent_id": null, "label": "...", "pengisi": "perawat", "hari_wajib": [1,2], "urutan": 1 }
}
```

---

## 3. copyFrom

**Method:** POST
**Endpoint:** /api/clinical-pathway/templates/{template}/copy-points
**Auth:** Bearer Token (wajib)

Menyalin **seluruh poin & sub-poin** dari satu formulir sumber ke formulir tujuan (`{template}`). Dipakai saat membuat formulir untuk diagnosa baru tanpa menyusun ulang dari awal.

### Body / Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| source_template_id | integer | Ya | ID formulir sumber. Harus ada di `template_clinical_pathway` dan **berbeda** dari template tujuan. |

### Perilaku
- Hierarki parent/sub-poin dipertahankan (parent_id dipetakan ke id poin yang baru dibuat).
- `urutan` melanjutkan dari poin yang sudah ada di formulir tujuan (poin disalin secara **append**, bukan menggantikan).
- `hari_wajib` yang melebihi `maksimal_hari` formulir tujuan otomatis diabaikan (di-filter ke rentang `1..maksimal_hari`).
- Seluruh proses dibungkus transaksi.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Berhasil menyalin 12 poin dari formulir sumber.",
  "data": [ { "id": 1, "template_id": 3, "categori_id": 1, "parent_id": null, "label": "...", "pengisi": "dokter", "hari_wajib": [1], "urutan": 1, "categori": { } } ]
}
```

#### Error (422)
```json
{ "status": false, "message": "Formulir sumber belum memiliki poin untuk disalin." }
```
Atau bila `source_template_id` sama dengan template tujuan / tidak ditemukan → `errors` dari validator.

---

## 5. update

**Method:** PUT/PATCH
**Endpoint:** /api/clinical-pathway/points/{point}
**Auth:** Bearer Token (wajib)

Body: `label`, `pengisi`, `hari_wajib` (validasi sama dengan store).

---

## 6. destroy

**Method:** DELETE
**Endpoint:** /api/clinical-pathway/points/{point}
**Auth:** Bearer Token (wajib)

Menghapus poin **beserta seluruh sub-poinnya** (rekursif).

### Response — Success (200)
```json
{ "status": true, "message": "Poin berhasil dihapus." }
```
