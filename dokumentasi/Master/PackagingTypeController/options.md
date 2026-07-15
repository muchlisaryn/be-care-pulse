# PackagingTypeController@options

**Controller:** App\Http\Controllers\Master\PackagingTypeController
**Method:** GET
**Endpoint:** /api/master/packaging-types/options
**Auth:** Bearer Token (wajib)

Pilihan jenis kemasan untuk dropdown "Jenis Kemasan" pada modal Selesai
Pengemasan, tanpa paginasi. `shelf_life_days` ikut dikirim agar FE bisa
menampilkan pratinjau tgl kedaluwarsa tanpa menyalin angka masa simpan.

Untuk menyembunyikan sebuah jenis dari dropdown, **hapus** jenis tersebut
(soft delete) — tidak ada status aktif/nonaktif.

`value` (= id master) inilah yang dikirim sebagai `packaging_type_id` ke
`POST /master/packaging/{packaging}/complete`.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
Tidak ada.

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Daftar jenis kemasan berhasil diambil.",
  "data": [
    { "value": 3, "label": "Container", "shelf_life_days": 30 },
    { "value": 1, "label": "Linen / Kain", "shelf_life_days": 7 },
    { "value": 2, "label": "Pouch Plastik", "shelf_life_days": 30 }
  ]
}
```

#### Error (401)
```json
{
  "status": false,
  "message": "Belum login atau token tidak valid."
}
```
