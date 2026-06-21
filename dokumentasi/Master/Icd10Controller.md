# Icd10Controller

**Controller:** App\Http\Controllers\Master\Icd10Controller
**Base URL:** /api/master/icd10

---

## 1. index

**Method:** GET
**Endpoint:** /api/master/icd10
**Auth:** Bearer Token (wajib)

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `code`, `display`, atau `version` |
| page | integer | Tidak | Halaman (paginate 20) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data ICD 10 berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      { "id": 1, "code": "A00", "display": "Cholera", "version": "2010" }
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
**Endpoint:** /api/master/icd10
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode ICD 10 |
| display | string | Ya | Nama/keterangan diagnosis |
| version | string | Ya | Versi ICD 10 |

### Response — Success (201)
```json
{
  "status": true,
  "message": "ICD 10 berhasil ditambahkan.",
  "data": { "id": 1, "code": "A00", "display": "Cholera", "version": "2010" }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "code": ["The code field is required."] }
}
```

---

## 3. show

**Method:** GET
**Endpoint:** /api/master/icd10/{icd10}
**Auth:** Bearer Token (wajib)

### Response — Success (200)
```json
{
  "status": true,
  "message": "Detail ICD 10 berhasil diambil.",
  "data": { "id": 1, "code": "A00", "display": "Cholera", "version": "2010" }
}
```

---

## 4. update

**Method:** PUT/PATCH
**Endpoint:** /api/master/icd10/{icd10}
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| code | string | Ya | Kode ICD 10 |
| display | string | Ya | Nama/keterangan diagnosis |
| version | string | Ya | Versi ICD 10 |

### Response — Success (200)
```json
{
  "status": true,
  "message": "ICD 10 berhasil diperbarui.",
  "data": { "id": 1, "code": "A00", "display": "Cholera", "version": "2010" }
}
```

---

## 5. destroy

**Method:** DELETE
**Endpoint:** /api/master/icd10/{icd10}
**Auth:** Bearer Token (wajib)

### Response — Success (200)
```json
{
  "status": true,
  "message": "ICD 10 berhasil dihapus."
}
```

---

## 6. import

**Method:** POST
**Endpoint:** /api/master/icd10/import
**Auth:** Bearer Token (wajib)

Impor massal dari Excel. File Excel di-parse di frontend menjadi baris JSON
`{ code, display, version }`, lalu dikirim ke endpoint ini. Baris yang kombinasi
`code` + `version`-nya **sudah ada di database** (atau duplikat di dalam batch
yang sama) akan **di-skip**.

**Hemat resource (data besar):** untuk file puluhan ribu baris, frontend mengirim
**per-batch** (mis. 1000 baris/request) secara berurutan, bukan sekaligus. Tiap
request: deteksi duplikat hanya untuk `code` yang relevan (`whereIn`), lalu
**bulk insert** (`insert()` di-chunk 500) — bukan `create()` per baris. Deteksi
duplikat tetap konsisten antar-batch karena tiap request mengecek ulang ke
database. Ringkasan tiap batch dijumlahkan di frontend.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| items | array | Ya | Minimal 1 baris |
| items.*.code | string | Ya | Kode ICD 10 |
| items.*.display | string | Ya | Nama/keterangan diagnosis |
| items.*.version | string | Ya | Versi ICD 10 |

### Response — Success (200)
`skipped_rows` berisi detail tiap baris yang dilewati beserta alasannya
(`Code & version sudah ada di database` atau `Duplikat di dalam file`).
```json
{
  "status": true,
  "message": "Impor ICD 10 selesai.",
  "data": {
    "imported": 120,
    "skipped": 5,
    "total": 125,
    "skipped_rows": [
      { "code": "A00", "display": "Cholera", "version": "ICD10_2010", "reason": "Code & version sudah ada di database" }
    ]
  }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "items": ["The items field is required."] }
}
```
