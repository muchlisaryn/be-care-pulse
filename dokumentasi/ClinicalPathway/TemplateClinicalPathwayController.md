# TemplateClinicalPathwayController

**Controller:** App\Http\Controllers\ClinicalPathway\TemplateClinicalPathwayController
**Base URL:** /api/clinical-pathway/templates

Template Clinical Pathway per diagnosa. Field: `icd10_id` (diagnosa, FK ke `icd10`),
`maksimal_hari`, `keterangan`, `is_active`. **Tidak ada endpoint hapus** — template
hanya bisa diaktifkan / dinonaktifkan (lihat endpoint `toggle`).

---

## 1. index

**Method:** GET
**Endpoint:** /api/clinical-pathway/templates
**Auth:** Bearer Token (wajib)

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `keterangan`, atau `code`/`display` ICD 10 |
| page | integer | Tidak | Halaman (paginate 20) |

Setiap item menyertakan `points_count` (jumlah poin formulir) untuk mendukung fitur **Salin dari Formulir Lain**.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data template clinical pathway berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "icd10_id": 1,
        "maksimal_hari": 5,
        "keterangan": "Rawat inap standar",
        "is_active": true,
        "points_count": 12,
        "icd10": { "id": 1, "code": "A00", "display": "Cholera", "version": "ICD10_2010" }
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

---

## 2. store

**Method:** POST
**Endpoint:** /api/clinical-pathway/templates
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| icd10_id | integer | Ya | ID diagnosa di tabel `icd10` |
| maksimal_hari | integer | Ya | Minimal 1 |
| keterangan | string | Tidak | Keterangan opsional |
| is_active | boolean | Tidak | Default `true` |

### Response — Success (201)
```json
{
  "status": true,
  "message": "Template clinical pathway berhasil ditambahkan.",
  "data": { "id": 1, "icd10_id": 1, "maksimal_hari": 5, "keterangan": null, "is_active": true, "icd10": { "id": 1, "code": "A00", "display": "Cholera", "version": "ICD10_2010" } }
}
```

---

## 3. show

**Method:** GET
**Endpoint:** /api/clinical-pathway/templates/{template}
**Auth:** Bearer Token (wajib)

---

## 4. update

**Method:** PUT/PATCH
**Endpoint:** /api/clinical-pathway/templates/{template}
**Auth:** Bearer Token (wajib)

Body sama dengan `store`.

---

## 5. toggleStatus

**Method:** PATCH
**Endpoint:** /api/clinical-pathway/templates/{template}/toggle
**Auth:** Bearer Token (wajib)

Membalik nilai `is_active` (aktif ⇄ non-aktif). Dipakai sebagai pengganti hapus —
template clinical pathway tidak bisa dihapus.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Template dinonaktifkan.",
  "data": { "id": 1, "is_active": false, "icd10": { "id": 1, "code": "A00", "display": "Cholera", "version": "ICD10_2010" } }
}
```

---

> Catatan: endpoint `DELETE /api/clinical-pathway/templates/{template}` **tidak tersedia**
> (resource didaftarkan dengan `->except(['destroy'])`).
