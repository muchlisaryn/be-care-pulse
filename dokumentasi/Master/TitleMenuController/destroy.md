# Destroy

**Method:** DELETE  
**Endpoint:** /api/master/title-menus/{id}  
**Controller:** App\Http\Controllers\Master\TitleMenuController@destroy  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID title menu |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Title menu berhasil dihapus.",
  "data": null
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
