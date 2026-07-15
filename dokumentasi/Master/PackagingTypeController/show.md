# PackagingTypeController@show

**Controller:** App\Http\Controllers\Master\PackagingTypeController
**Method:** GET
**Endpoint:** /api/master/packaging-types/{packaging_type}
**Auth:** Bearer Token (wajib)

Detail satu jenis kemasan.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Detail jenis kemasan berhasil diambil.",
  "data": { "id": 2, "code": "PKS-002", "name": "Pouch Plastik", "shelf_life_days": 30, "note": null }
}
```

#### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
