# AsesmenClinicalPathwayController

**Controller:** App\Http\Controllers\ClinicalPathway\AsesmenClinicalPathwayController
**Base URL:** /api/clinical-pathway/asesmen

Asesmen = pengisian clinical pathway untuk satu pasien. Header berisi data pasien + diagnosa (mengacu ke satu `template`/formulir). Pengisian ceklis per poin disimpan terpisah dan di-auto-save lewat endpoint `savePoint`.

---

## 1. index

**Method:** GET
**Endpoint:** /api/clinical-pathway/asesmen
**Auth:** Bearer Token (wajib)

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `nama_pasien`, `no_rm`, atau `diagnosa_masuk` |
| ruang_id | integer | Tidak | Filter berdasarkan ruang rawat (`rooms.id`) |
| status | string | Tidak | Filter status verifikasi: `selesai` (pelaksana sudah verifikasi) atau `belum` |
| page | integer | Tidak | Halaman (paginate 20) |

Setiap item menyertakan relasi `template.icd10` (untuk menampilkan diagnosa formulir).

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data asesmen clinical pathway berhasil diambil.",
  "data": { "current_page": 1, "data": [ { "id": 1, "nama_pasien": "Budi", "jenis_kelamin": "L", "diagnosa_masuk": "Demam", "template": { "id": 3, "maksimal_hari": 5, "icd10": { "code": "A00", "display": "Cholera" } } } ], "last_page": 1, "per_page": 20, "total": 1 }
}
```

---

## 2. store

**Method:** POST
**Endpoint:** /api/clinical-pathway/asesmen
**Auth:** Bearer Token (wajib)

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| template_id | integer | Ya | Formulir/diagnosa yang dipakai. Harus ada di `template_clinical_pathway`. |
| no_rm | string | Ya | Nomor rekam medis pasien. |
| nama_pasien | string | Ya | |
| jenis_kelamin | string | Tidak | `L` atau `P` |
| tanggal_lahir | date | Tidak | `YYYY-MM-DD` |
| diagnosa_masuk | string | Tidak | |
| penyakit_utama | string | Tidak | |
| penyakit_penyerta | string | Tidak | |
| komplikasi | string | Tidak | |
| tindakan | string | Tidak | |
| bb | numeric | Tidak | Berat badan (kg) |
| tb | numeric | Tidak | Tinggi badan (cm) |
| tanggal_jam_masuk | datetime | Tidak | `YYYY-MM-DD HH:mm` |
| tanggal_jam_keluar | datetime | Tidak | `after_or_equal:tanggal_jam_masuk` |
| lama_rawat | integer | Tidak | Hari (diisi manual) |
| rencana_rawat | string | Tidak | |
| ruang_id | integer | Ya | Ruang rawat dari master ruangan. Harus ada di `rooms`. |
| kelas | string | Tidak | Kelas perawatan pasien (mis. Kelas 1, VIP). |
| rujukan | boolean | Tidak | Default `false` (input pilihan Ya/Tidak) |

### Response — Success (201)
```json
{ "status": true, "message": "Asesmen berhasil dibuat.", "data": { "id": 1, "...": "...", "template": { } } }
```

---

## 3. show

**Method:** GET
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}
**Auth:** Bearer Token (wajib)

Mengembalikan header asesmen + relasi `template.icd10` + `ruang` (master ruangan) + `points` (seluruh nilai ceklis yang sudah tersimpan).

### Response — Success (200)
```json
{
  "status": true,
  "message": "Detail asesmen berhasil diambil.",
  "data": {
    "id": 1, "template_id": 3, "no_rm": "RM-001", "nama_pasien": "Budi", "ruang_id": 2, "...": "...",
    "template": { "id": 3, "maksimal_hari": 5, "icd10": { "code": "A00", "display": "Cholera" } },
    "ruang": { "id": 2, "name": "Annur 1" },
    "points": [ { "id": 10, "asesmen_id": 1, "point_id": 7, "checked_hari": [1,3], "keterangan": "Stabil" } ]
  }
}
```

---

## 4. update

**Method:** PUT/PATCH
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}
**Auth:** Bearer Token (wajib)

Body sama dengan **store**.

---

## 5. destroy

**Method:** DELETE
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}
**Auth:** Bearer Token (wajib)

Soft delete asesmen (mengikuti trait `HasAuditColumns`).

### Response — Success (200)
```json
{ "status": true, "message": "Asesmen berhasil dihapus." }
```

---

## 6. savePoint

**Method:** PUT
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}/points/{point}
**Auth:** Bearer Token (wajib)

Upsert nilai ceklis **satu poin** pada asesmen. Dipakai untuk auto-save saat user menceklis hari atau mengetik keterangan. Satu poin hanya punya satu baris nilai per asesmen (unik `asesmen_id` + `point_id`).

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| checked_hari | array | Tidak | Array angka hari yang diceklis. Tiap nilai `1..maksimal_hari` template. Nilai duplikat dibuang. |
| keterangan | string | Tidak | Keterangan poin. |

### Response — Success (200)
```json
{ "status": true, "message": "Pengisian poin tersimpan.", "data": { "id": 10, "asesmen_id": 1, "point_id": 7, "checked_hari": [1,3], "keterangan": "Stabil" } }
```

#### Error (422)
```json
{ "status": false, "message": "Poin tidak termasuk dalam formulir asesmen ini." }
```
Atau error validasi bila `checked_hari.*` di luar rentang `1..maksimal_hari`.

---

## 7. verify

**Method:** POST  
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}/verify  
**Auth:** Bearer Token (wajib)

Verifikasi / batal verifikasi clinical pathway untuk satu peran. Tiap verifikasi
menyimpan username pemverifikasi (`verifikasi_{role}_by`) + waktunya
(`verifikasi_{role}_at`) di tabel asesmen.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| role | string | Ya | Salah satu: `dokter`, `perawat`, `pelaksana`. |
| action | string | Ya | `verify` (verifikasi) atau `batal` (batal verifikasi). |

### Aturan
- `pelaksana` + `verify` hanya boleh bila **dokter & perawat** sudah verifikasi (jika tidak → 422).
- `dokter`/`perawat` + `batal` ditolak selama **pelaksana** masih terverifikasi (batalkan pelaksana dulu → 422).

### Response — Success (200)
```json
{
  "status": true,
  "message": "Verifikasi berhasil disimpan.",
  "data": {
    "id": 2,
    "verifikasi_dokter_by": "dr.budi",
    "verifikasi_dokter_at": "2026-06-21T09:00:00.000000Z",
    "verifikasi_perawat_by": null,
    "verifikasi_perawat_at": null,
    "verifikasi_pelaksana_by": null,
    "verifikasi_pelaksana_at": null
  }
}
```

#### Error (422)
```json
{ "status": false, "message": "Verifikasi dokter & perawat penanggung jawab harus selesai dulu." }
```

---

## 8. pdf

**Method:** GET  
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}/pdf  
**Auth:** Bearer Token (wajib)

Cetak asesmen ke PDF (data pasien + ceklis poin per hari + pencatatan varian +
verifikasi). Dibuat dengan dompdf (A4 landscape) dan dikembalikan **inline**
(`Content-Type: application/pdf`). Frontend mengambilnya sebagai blob (agar token
Bearer ikut terkirim), menampilkannya di iframe, dan menyediakan tombol download.

### Response
- **200** — body biner PDF, header `Content-Type: application/pdf`.
- **404** — asesmen tidak ditemukan.
