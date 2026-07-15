# PackagingTypeController@index

**Controller:** App\Http\Controllers\Master\PackagingTypeController
**Method:** GET
**Endpoint:** /api/master/packaging-types
**Auth:** Bearer Token (wajib)

Daftar master jenis kemasan (tahap Packaging), dipaginasi 20/halaman.
`shelf_life_days` adalah masa simpan sterilnya — menentukan tgl kedaluwarsa batch
yang dikemas memakai jenis ini.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada `name` atau `code` |
| page | integer | Tidak | Nomor halaman |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data jenis kemasan berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      { "id": 2, "code": "PKS-002", "name": "Pouch Plastik", "shelf_life_days": 30, "note": null }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
}
```
