# SterilizerMachineController@destroy

**Controller:** App\Http\Controllers\Master\SterilizerMachineController
**Method:** DELETE
**Endpoint:** /api/master/sterilizer-machines/{sterilizer_machine}
**Auth:** Bearer Token (wajib)

Hapus (soft delete) mesin sterilisator.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Mesin sterilisator berhasil dihapus."
}
```

#### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
