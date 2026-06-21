# CategoriClinicalPathwayController

**Controller:** App\Http\Controllers\ClinicalPathway\CategoriClinicalPathwayController
**Base URL:** /api/clinical-pathway/categories

Kategori (template) Clinical Pathway. Field: `urutan` (integer, **unik**) dan `label`.

---

## 1. index

**Method:** GET
**Endpoint:** /api/clinical-pathway/categories
**Auth:** Bearer Token (wajib)

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `label` atau `urutan` |
| page | integer | Tidak | Halaman (paginate 20) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data kategori clinical pathway berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      { "id": 1, "urutan": 1, "label": "Anamnesis" }
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
**Endpoint:** /api/clinical-pathway/categories
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| urutan | integer | Ya | Urutan, minimal 1, **harus unik** |
| label | string | Ya | Label kategori |

### Response — Success (201)
```json
{
  "status": true,
  "message": "Kategori clinical pathway berhasil ditambahkan.",
  "data": { "id": 1, "urutan": 1, "label": "Anamnesis" }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "urutan": ["The urutan has already been taken."] }
}
```

---

## 3. show

**Method:** GET
**Endpoint:** /api/clinical-pathway/categories/{categori}
**Auth:** Bearer Token (wajib)

### Response — Success (200)
```json
{
  "status": true,
  "message": "Detail kategori clinical pathway berhasil diambil.",
  "data": { "id": 1, "urutan": 1, "label": "Anamnesis" }
}
```

---

## 4. update

**Method:** PUT/PATCH
**Endpoint:** /api/clinical-pathway/categories/{categori}
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| urutan | integer | Ya | Urutan, minimal 1, **unik** (kecuali baris ini sendiri) |
| label | string | Ya | Label kategori |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Kategori clinical pathway berhasil diperbarui.",
  "data": { "id": 1, "urutan": 1, "label": "Anamnesis" }
}
```

---

## 5. destroy

**Method:** DELETE
**Endpoint:** /api/clinical-pathway/categories/{categori}
**Auth:** Bearer Token (wajib)

### Response — Success (200)
```json
{
  "status": true,
  "message": "Kategori clinical pathway berhasil dihapus."
}
```
