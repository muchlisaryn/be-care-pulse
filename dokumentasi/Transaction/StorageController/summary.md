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

**Aturan hitung:** baris `paket` dihitung **per SET** — dikelompokkan per nomor
label kemasan (`sterilization_item.packaging_barcode`) pada batch steril yang sama,
karena satu label = satu bungkus = satu set — sedangkan baris `satuan` dihitung
**per unit**. Jadi satu paket berisi 5 instrumen bernilai 1, bukan 5. Bungkus tanpa
nomor label dihitung sebagai set tersendiri agar jumlahnya tidak mengecil palsu.
Aturan ini sama dengan `borrowed_count` pada `MonitoringController@rooms`.

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
| total | Jumlah instrumen di rak gudang steril (paket = 1 per set, satuan = per unit) |
| alert | Instrumen yang kedaluwarsa dalam ≤ `days` hari (belum lewat), aturan hitung sama |
| expired | Instrumen yang tanggal kedaluwarsanya sudah lewat, aturan hitung sama |

### Response — Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
}
```
