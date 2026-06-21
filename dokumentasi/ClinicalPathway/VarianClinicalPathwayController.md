# VarianClinicalPathwayController

**Controller:** App\Http\Controllers\ClinicalPathway\VarianClinicalPathwayController  
**Base URL:** /api/clinical-pathway

Pencatatan varian (penyimpangan) clinical pathway per asesmen pasien. Satu asesmen
bisa memiliki banyak catatan varian. Kolom **paraf** selalu diisi otomatis dari
`username` user yang sedang login — tidak dikirim dari body request.

---

## 1. index

**Method:** GET  
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}/varian  
**Auth:** Bearer Token (wajib)

Mengembalikan seluruh catatan varian milik satu asesmen (urut terbaru di atas),
tidak dipaginasi karena ditampilkan inline pada halaman detail asesmen.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter pada `varian` atau `alasan`. |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data varian clinical pathway berhasil diambil.",
  "data": [
    {
      "id": 1,
      "asesmen_id": 2,
      "tanggal_waktu": "2026-06-21T08:30:00.000000Z",
      "varian": "Pasien menolak pemberian obat",
      "alasan": "Riwayat alergi",
      "paraf": "perawat01",
      "created_at": "2026-06-21T08:31:00.000000Z",
      "updated_at": "2026-06-21T08:31:00.000000Z"
    }
  ]
}
```

---

## 2. store

**Method:** POST  
**Endpoint:** /api/clinical-pathway/asesmen/{asesmen}/varian  
**Auth:** Bearer Token (wajib)

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| tanggal_waktu | datetime | Ya | Tanggal & waktu varian terjadi (`YYYY-MM-DD HH:mm`). |
| varian | string | Ya | Varian yang terjadi. |
| alasan | string | Tidak | Alasan varian terjadi. |

> **paraf** TIDAK dikirim — diisi otomatis dari username user yang login.

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Catatan varian berhasil ditambahkan.",
  "data": { "id": 1, "asesmen_id": 2, "tanggal_waktu": "...", "varian": "...", "alasan": "...", "paraf": "perawat01" }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "varian": ["pesan error"] }
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

---

## 3. update

**Method:** PUT  
**Endpoint:** /api/clinical-pathway/varian/{varian}  
**Auth:** Bearer Token (wajib)

Body sama dengan `store`. **paraf** ikut diperbarui ke username user yang melakukan
perubahan.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Catatan varian berhasil diperbarui.",
  "data": { "id": 1, "...": "..." }
}
```

---

## 4. destroy

**Method:** DELETE  
**Endpoint:** /api/clinical-pathway/varian/{varian}  
**Auth:** Bearer Token (wajib)

### Response — Success (200)
```json
{
  "status": true,
  "message": "Catatan varian berhasil dihapus."
}
```
