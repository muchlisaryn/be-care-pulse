# WasherMachineController@destroy

**Controller:** App\Http\Controllers\Master\WasherMachineController
**Method:** DELETE
**Endpoint:** /api/master/washer-machines/{washer_machine}
**Auth:** Bearer Token (wajib)

Soft delete mesin pencuci (set `deleted_by` + `deleted_at`).

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Response

#### Success (200)
```json
{ "status": true, "message": "Mesin washer berhasil dihapus." }
```

#### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
