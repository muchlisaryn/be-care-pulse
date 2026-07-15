# PackagingTypeController@update

**Controller:** App\Http\Controllers\Master\PackagingTypeController
**Method:** PUT/PATCH
**Endpoint:** /api/master/packaging-types/{packaging_type}
**Auth:** Bearer Token (wajib)

Perbarui master jenis kemasan.

> **Catatan:** mengubah `shelf_life_days` **tidak** menggeser tgl kedaluwarsa batch
> yang sudah terlanjur dikemas — `packaging.expiry_date` disimpan sebagai snapshot
> saat pengemasan. Nilai baru hanya berlaku untuk pengemasan berikutnya.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama jenis kemasan |
| shelf_life_days | integer | Ya | Masa simpan steril (hari, min 1) |
| note | string | Tidak | Catatan |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Jenis kemasan berhasil diperbarui.",
  "data": { "id": 2, "code": "PKS-002", "name": "Pouch Plastik", "shelf_life_days": 60 }
}
```

#### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "shelf_life_days": ["The shelf life days field must be at least 1."] }
}
```
