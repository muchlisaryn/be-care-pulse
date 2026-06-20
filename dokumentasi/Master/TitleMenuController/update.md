# Update

**Method:** PUT / PATCH  
**Endpoint:** /api/master/title-menus/{id}  
**Controller:** App\Http\Controllers\Master\TitleMenuController@update  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID title menu |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| title | string | Tidak | Nama title menu |
| sort_order | integer | Tidak | Urutan tampil |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Title menu berhasil diperbarui.",
  "data": {
    "id": 1,
    "title": "Master Data",
    "sort_order": 1,
    "menus": [
      { "id": 1, "name": "Authority", "url": "/master/otoritas", "sort_order": 1 }
    ]
  }
}
```

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {}
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
