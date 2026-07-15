# PackagingTypeController@store

**Controller:** App\Http\Controllers\Master\PackagingTypeController
**Method:** POST
**Endpoint:** /api/master/packaging-types
**Auth:** Bearer Token (wajib)

Tambah master jenis kemasan. `code` (PKS-NNN) di-generate otomatis.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama jenis kemasan (mis. Pouch Plastik) |
| shelf_life_days | integer | Ya | Masa simpan steril (hari, min 1). Tgl kedaluwarsa batch = tgl kemas + nilai ini |
| note | string | Tidak | Catatan |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Jenis kemasan berhasil ditambahkan.",
  "data": { "id": 4, "code": "PKS-004", "name": "Pouch Plastik Besar", "shelf_life_days": 30 }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "shelf_life_days": ["The shelf life days field is required."] }
}
```
