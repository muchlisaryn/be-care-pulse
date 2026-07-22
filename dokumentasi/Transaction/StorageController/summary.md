# StorageController@summary

**Controller:** App\Http\Controllers\Transaction\StorageController
**Method:** GET
**Endpoint:** /api/master/storage/summary
**Auth:** Bearer Token (wajib)

Angka ringkasan gudang steril untuk kartu statistik halaman Storage Steril:
total unit tersimpan, yang mendekati kedaluwarsa, dan yang sudah kedaluwarsa.

Dibuat terpisah dari `inventory` karena daftar inventaris dimuat **bertahap**
(lazy load per halaman) — angka ringkasan tetap harus mencerminkan seluruh data,
bukan hanya baris yang sudah dimuat di layar.

**Basis perhitungan sama dengan `inventory`:** hanya baris gudang berstatus
`tersimpan` yang unitnya masih berkondisi `tersedia`.

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| days | integer | Tidak | Ambang early-warning dalam hari (default 7) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Ringkasan gudang steril berhasil diambil.",
  "data": {
    "total": 128,
    "alert": 9,
    "expired": 3
  }
}
```

| Field | Keterangan |
|-------|------------|
| total | Jumlah unit yang sedang berada di rak gudang steril |
| alert | Unit yang kedaluwarsa dalam ≤ `days` hari (belum lewat) |
| expired | Unit yang tanggal kedaluwarsanya sudah lewat |

### Response — Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
}
```
