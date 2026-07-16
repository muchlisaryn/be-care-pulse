# PackagingTypeController@destroy

**Controller:** App\Http\Controllers\Master\PackagingTypeController
**Method:** DELETE
**Endpoint:** /api/master/packaging-types/{packaging_type}
**Auth:** Bearer Token (wajib)

Hapus (soft delete) jenis kemasan. Jenis yang dihapus tidak lagi muncul di dropdown
pengemasan, tetapi **tetap terbaca** pada riwayat & label batch lama yang memakainya
(relasi `Packaging::packagingType()` sengaja mengabaikan global scope `active`).

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Jenis kemasan berhasil dihapus."
}
```

#### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
