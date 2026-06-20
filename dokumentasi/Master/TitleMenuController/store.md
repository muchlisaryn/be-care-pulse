# Store

**Method:** POST  
**Endpoint:** /api/master/title-menus  
**Controller:** App\Http\Controllers\Master\TitleMenuController@store  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| title | string | Ya | Nama title menu, maks 100 karakter |
| sort_order | integer | Tidak | Urutan tampil (default: 0) |

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Title menu berhasil dibuat.",
  "data": {
    "id": 1,
    "title": "Master Data",
    "sort_order": 1
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "title": ["The title field is required."]
  }
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
