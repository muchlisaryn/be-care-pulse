# destroy

**Method:** DELETE  
**Endpoint:** `/api/master/sterilizations/{sterilization}`  
**Controller:** `App\Http\Controllers\Transaction\SterilizationController@destroy`  
**Auth:** Bearer Token (wajib)

Soft delete batch sterilisasi (mengisi `deleted_at` + `deleted_by`). Data tidak hilang permanen.
Status unit instrumen tidak diubah oleh operasi ini.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| sterilization | integer | ID batch sterilisasi |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Batch sterilisasi berhasil dihapus."
}
```

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
